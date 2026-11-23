<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use DBConnection;
use Entity;
use Glpi\Asset\Asset;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use RuntimeException;
use Throwable;

class SyncRunner
{
    private const BATCH_LIMIT = 100;

    public static function install(): bool
    {
        global $DB;

        $table = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        $sign = DBConnection::getDefaultPrimaryKeySignOption();
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $DB->doQuery("
            CREATE TABLE IF NOT EXISTS $table (
                id int $sign NOT NULL AUTO_INCREMENT,
                routes_id int $sign NOT NULL,
                asset_type varchar(255) NOT NULL,
                assets_id int $sign NOT NULL,
                serial varchar(255) NOT NULL,
                asset_name varchar(255) NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'pending',
                sync_direction varchar(8) NULL,
                last_error text NULL,
                last_error_details text NULL,
                last_attempt timestamp NULL DEFAULT NULL,
                field_snapshot mediumtext NULL,
                pending_fields mediumtext NULL,
                date_mod timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY route_asset (routes_id, asset_type, assets_id),
                KEY route_status (routes_id, status),
                KEY last_attempt (last_attempt, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
        ");

        // Existing status records have no reliable transfer direction until the next successful write.
        if (!$DB->fieldExists($table, 'sync_direction', false))
        {
            $DB->doQuery("ALTER TABLE $table ADD sync_direction varchar(8) NULL");
        }
        if (!$DB->fieldExists($table, 'last_error_details', false))
        {
            $DB->doQuery("ALTER TABLE $table ADD last_error_details text NULL");
        }
        if (!$DB->fieldExists($table, 'pending_fields', false))
        {
            $DB->doQuery("ALTER TABLE $table ADD pending_fields mediumtext NULL");
            // The old scheduler could leave already-synced assets queued for a routine check.
            // A manual retry, which clears last_attempt, remains queued.
            $DB->update($table, ['status' => 'finished', 'last_error' => null], [
                'status' => ['pending', 'queued'],
                'AND' => [
                    ['NOT' => ['field_snapshot' => null]],
                    ['NOT' => ['last_attempt' => null]],
                ],
            ]);
        }
        SyncChanges::install();
        return true;
    }

    public static function uninstall(): bool
    {
        global $DB;
        $DB->dropTable(PLUGIN_ASSETSYNCCONTROLLER_CURSORS_TABLE, true);
        return $DB->dropTable(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, true);
    }

    public static function cancelQueued(int $routeId): int
    {
        global $DB;

        // The worker also checks status when claiming a row. Only one transition can win.
        $DB->update(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, [
            'status' => 'cancelled',
            'pending_fields' => null,
            'last_error' => null,
            'last_error_details' => null,
        ], ['routes_id' => $routeId, 'status' => 'queued']);
        return $DB->affectedRows();
    }

    public static function run(): int
    {
        global $DB;

        $syncTable = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        // GLPI's task lock prevents overlap. Any Processing row left here lost its previous run.
        $DB->update($syncTable, [
            'status' => 'failed',
            'last_error' => 'The previous sync run stopped before Controller A could confirm the result. Check the Agent B connection, then retry this asset to verify its current state.',
            'last_error_details' => null,
        ], ['status' => 'processing']);
        $targets = self::discover();
        if ($targets === [])
        {
            return 0;
        }

        $agents = [];
        try
        {
            $scope = [];
            foreach ($targets as $target)
            {
                $route = $target['route'];
                try
                {
                    RouteSettings::withLock((int) $route['id'], static function () use ($route, $target, &$agents): void
                    {
                        $connection = self::getConnection($route);
                        if ($connection !== null
                            && $target['mappings'] === self::getMappings((int) $route['id'], $target['type']))
                        {
                            SyncChanges::collect($target, self::openAgent($connection, $agents));
                        }
                    });
                }
                catch (Throwable)
                {
                    // Avoid logging field values or credentials from a failed discovery.
                    \Toolbox::logInFile('cron', "AssetSync change discovery failed for route {$route['id']}.\n");
                }
                $scope[] = ['routes_id' => $route['id'], 'asset_type' => $target['type']];
            }
            return $scope === [] ? 0 : self::runBatch($targets, $scope, $agents);
        }
        finally
        {
            foreach ($agents as $agent)
            {
                $agent?->close();
            }
        }
    }

    private static function runBatch(array $targets, array $scope, array &$agents): int
    {
        global $DB;

        $syncTable = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        $batch = iterator_to_array($DB->request([
            'FROM' => $syncTable,
            'WHERE' => ['OR' => $scope, 'serial' => ['!=', ''], 'status' => ['pending', 'queued']],
            'ORDER' => [new QueryExpression("CASE WHEN status = 'queued' THEN 0 ELSE 1 END"), 'last_attempt', 'id'],
            'LIMIT' => self::BATCH_LIMIT,
        ]), false);
        if ($batch === [])
        {
            return 0;
        }

        // A queue cancellation can happen after selection. Only promote rows still pending.
        $DB->update($syncTable, ['status' => 'queued'], [
            'id' => array_column($batch, 'id'),
            'status' => 'pending',
        ]);
        $groups = [];
        foreach ($batch as $entry)
        {
            $route = $targets[$entry['routes_id'] . ':' . $entry['asset_type']]['route'];
            $groups[$route['connections_id'] . ':' . $route['connection_revision']][] = $entry;
        }

        $attempted = 0;
        $started = hrtime(true);
        foreach ($groups as $entries)
        {
            // Leave unstarted groups queued when the worker's time budget runs out.
            if (hrtime(true) - $started >= 50_000_000_000)
            {
                break;
            }
            $routeIds = array_values(array_unique(array_map('intval', array_column($entries, 'routes_id'))));
            sort($routeIds, SORT_NUMERIC);
            $attempted += self::withBatchRoutes($routeIds, static function (array $locked) use ($entries, $targets, &$agents): int
            {
                return self::runConnectionBatch($entries, $targets, $locked, $agents);
            });
        }
        return $attempted;
    }

    private static function withBatchRoutes(array $routeIds, callable $run, array $locked = []): int
    {
        if ($routeIds === [])
        {
            return $run($locked);
        }

        $routeId = array_shift($routeIds);
        $acquired = false;
        try
        {
            return RouteSettings::withLock($routeId, static function () use ($routeIds, $run, $locked, $routeId, &$acquired): int
            {
                $acquired = true;
                $locked[$routeId] = true;
                return self::withBatchRoutes($routeIds, $run, $locked);
            });
        }
        catch (SyncProblem $problem)
        {
            if ($acquired)
            {
                throw $problem;
            }
            // An administrator is editing this route; other routes can still use the batch.
            return self::withBatchRoutes($routeIds, $run, $locked);
        }
    }

    private static function runConnectionBatch(array $entries, array $targets, array $locked, array &$agents): int
    {
        global $DB;

        $table = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        $steps = [];
        $agent = null;
        $attempted = 0;
        foreach ($entries as $entry)
        {
            if (!isset($locked[$entry['routes_id']]))
            {
                continue;
            }
            $where = [
                'id' => $entry['id'], 'serial' => $entry['serial'],
                'status' => 'queued', 'pending_fields' => $entry['pending_fields'],
            ];
            try
            {
                $target = $targets[$entry['routes_id'] . ':' . $entry['asset_type']];
                $route = $target['route'];
                $connection = self::getConnection($route);
                if ($connection === null)
                {
                    throw new SyncProblem('The route or its connection changed before this asset could sync. Check that both are enabled and reverify the route after changing the connection.');
                }
                $asset = self::loadQueuedAsset($entry, $target);
                $agent ??= self::openAgent($connection, $agents);
                if ($agent === null)
                {
                    throw new SyncProblem('Controller A could not start an authenticated session with Agent B. Use Test Connection to check the saved URL and API tokens.');
                }
                $flags = SyncFlags::get((int) $route['id'], $entry['asset_type']);
                $plan = SyncPlan::steps($asset, $route, $target['mappings'], $entry, $flags);
                $plan->current();

                // Cancel and dispatch compete on the same conditional queue update.
                $DB->update($table, ['status' => 'processing', 'last_attempt' => QueryFunction::now()], $where);
                if ($DB->affectedRows() === 0)
                {
                    continue;
                }
                ++$attempted;
                $where['status'] = 'processing';
                if (!$plan->valid())
                {
                    self::finishEntry($plan->getReturn(), $where);
                    continue;
                }
                $steps[(int) $entry['id']] = ['plan' => $plan, 'where' => $where];
            }
            catch (Throwable $error)
            {
                $failed = self::failEntry($error, $where);
                if ($where['status'] === 'queued')
                {
                    $attempted += $failed;
                }
            }
        }

        // The first batch reads B. Only assets requiring another request enter the apply batch.
        while ($steps !== [])
        {
            $requests = [];
            foreach ($steps as $id => $step)
            {
                $requests[] = ['c_id' => $id] + $step['plan']->current();
            }
            try
            {
                $results = $agent->syncBatch($requests);
            }
            catch (Throwable)
            {
                // A lost batch cannot acknowledge any individual result.
                $results = [];
            }
            foreach ($steps as $id => $step)
            {
                try
                {
                    $plan = $step['plan'];
                    $response = $results[$id] ?? null;
                    if ($response === null)
                    {
                        // Missing acknowledgements are ambiguous on a write. Retrying looks up the asset again.
                        $isWrite = $plan->current()['operation'] === 'apply';
                        $message = $isWrite
                            ? 'Controller A did not receive a valid write acknowledgement from Agent B. The write may have completed.'
                            : 'Controller A did not receive a valid lookup response from Agent B for this asset.';
                        throw new SyncProblem($message, details: [
                            'side' => 'b',
                            'next_step' => 'Check Test Connection, the Agent B plugin version, and GLPI B logs. Then retry this asset.',
                        ]);
                    }
                    $plan->send($response);
                    if ($plan->valid())
                    {
                        continue;
                    }
                    self::finishEntry($plan->getReturn(), $step['where']);
                }
                catch (Throwable $error)
                {
                    self::failEntry($error, $step['where']);
                }
                unset($steps[$id]);
            }
        }
        return $attempted;
    }

    private static function loadQueuedAsset(array $entry, array $target): CommonDBTM
    {
        global $DB;

        $type = $entry['asset_type'];
        $asset = new $type();
        if (!$asset->getFromDB($entry['assets_id']))
        {
            throw new SyncProblem('The queued asset no longer exists in GLPI A. If it was recreated, queue the new asset from its current route.');
        }
        if ((int) $asset->fields['entities_id'] !== (int) $target['route']['controller_entity_id']
            || !empty($asset->fields['is_deleted']) || !empty($asset->fields['is_template']))
        {
            throw new SyncProblem('The queued asset is outside the mapped entity, in the trash, or a template in GLPI A. Restore it or use a route for its current entity.');
        }
        if (trim((string) $asset->fields['serial']) !== $entry['serial'])
        {
            throw new SyncProblem('The asset serial number in GLPI A changed after it was queued. Confirm the serial number, then queue the current asset again.');
        }

        // Multiple live A records with the same serial would target one B record.
        $duplicates = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $type::getTable(),
            'WHERE' => $target['criteria'] + ['RAW' => ['TRIM(serial)' => $entry['serial']]],
            'LIMIT' => 2,
        ]);
        if (count($duplicates) !== 1)
        {
            throw new SyncProblem('Controller A could not find exactly one active asset of this type with the queued serial number in the mapped entity. Check for duplicate serial numbers or a recently moved asset.');
        }
        return $asset;
    }

    private static function finishEntry(array $result, array $where): void
    {
        global $DB;

        $status = [
            'status' => 'finished',
            'last_error' => null,
            'last_error_details' => null,
            'pending_fields' => null,
            'field_snapshot' => json_encode($result['snapshot'], JSON_THROW_ON_ERROR),
        ];
        if ($result['direction'] !== null)
        {
            $status['sync_direction'] = $result['direction'];
        }
        $DB->update(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, $status, $where);
    }

    private static function failEntry(Throwable $error, array $where): int
    {
        global $DB;

        // Database and HTTP exception messages can contain asset data or credentials.
        $message = 'An unexpected Controller A error interrupted this asset sync. Check GLPI A’s PHP and automatic-action logs before retrying.';
        $details = [];
        if ($error instanceof SyncProblem)
        {
            $message = $error->getMessage();
            $details = $error->details;
        }
        elseif ($error instanceof SyncException)
        {
            $message = match ($error->getMessage()) {
                'invalid_field', 'field_unavailable' => 'A mapped field is unavailable in GLPI A. Reload the route’s field list and check that the field still exists and is visible to the sync account.',
                'field_read_only' => 'A mapped field is read-only in GLPI A. Check its edit permissions and the GLPI A write account in Connections, or change the mapping direction.',
                'reference_unavailable' => 'GLPI A could not resolve a mapped dropdown or item reference. Verify the value exists and is unique and visible in the mapped entity.',
                'invalid_reference_value' => 'GLPI A received an invalid dropdown or item reference. Check that the mapped fields accept the same kind of value.',
                'invalid_field_value' => 'A mapped value does not match the field type or format required by GLPI A. Compare the mapping’s field types and the source value.',
                'notes_write_failed' => 'GLPI A could not retain the copied Notes record. Check the write account’s Notes permissions for this asset and review the GLPI A logs.',
                'custom_field_write_failed' => 'GLPI A rejected an update to a custom field. Check required fields, allowed values, and edit permissions on the Fields container.',
                'asset_update_failed' => 'GLPI A could not save or reload the asset after updating it. Check whether the asset still exists and review its field validation and the GLPI A logs.',
                'asset_history_failed' => 'GLPI A could not save the sync history entry. Check the GLPI A database and PHP logs, then retry.',
                default => 'GLPI A rejected a mapped update. Check the asset’s mappings and review GLPI A’s PHP and automatic-action logs before retrying.',
            };
        }
        $DB->update(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, [
            'status' => 'failed',
            'last_attempt' => QueryFunction::now(),
            'last_error' => $message,
            'last_error_details' => $details === [] ? null : json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
        ], $where);
        return $DB->affectedRows();
    }

    private static function openAgent(array $connection, array &$agents): ?AgentApi
    {
        $key = (int) $connection['id'] . ':' . (int) $connection['settings_revision'];
        if (!array_key_exists($key, $agents))
        {
            $agents[$key] = AgentApi::open($connection['api_url'], $connection['application_token'], $connection['user_token']);
        }
        return $agents[$key];
    }

    private static function discover(): array
    {
        global $DB;

        $routesTable = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
        $connectionsTable = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
        $targets = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => $routesTable]) as $row)
        {
            try
            {
                RouteSettings::withLock((int) $row['id'], static function () use ($DB, $routesTable, $connectionsTable, $row, &$targets): void
                {
                    // Read the route inside the lock. Inventory from an older scope must not refill cleared rows.
                    $route = $DB->request([
                        'SELECT' => ["$routesTable.*", "$connectionsTable.settings_revision AS current_revision",
                            "$connectionsTable.is_enabled AS connection_enabled"],
                        'FROM' => $routesTable,
                        'LEFT JOIN' => [$connectionsTable => ['ON' => [$routesTable => 'connections_id', $connectionsTable => 'id']]],
                        'WHERE' => ["$routesTable.id" => $row['id']],
                    ])->current();
                    if ($route)
                    {
                        $targets += self::discoverRoute($route);
                    }
                });
            }
            catch (SyncProblem)
            {
                // An administrator is editing this route. A later run will rediscover it.
            }
        }
        return $targets;
    }

    private static function discoverRoute(array $route): array
    {
        global $DB;

        $targets = [];
        if (!count($DB->request(['SELECT' => ['id'], 'FROM' => Entity::getTable(),
            'WHERE' => ['id' => $route['controller_entity_id']]])))
        {
            return $targets;
        }

        foreach (array_keys(json_decode($route['asset_types'], true) ?? []) as $type)
        {
            $criteria = self::assetCriteria($type, (int) $route['controller_entity_id']);
            if ($criteria === null)
            {
                continue;
            }

            $mappings = self::getMappings((int) $route['id'], $type);
            $blocked = null;
            if (!$route['is_enabled'])
            {
                $blocked = 'This route is disabled. Enable it in Routes to sync eligible assets.';
            }
            elseif (!$route['connection_enabled'] || (int) $route['connection_revision'] !== (int) $route['current_revision'])
            {
                $blocked = 'The connection is disabled or the route needs verification. Check Connections and reverify the route.';
            }
            elseif ($mappings === [])
            {
                $blocked = 'No fields are mapped for this asset type. Add field mappings for this route and asset type.';
            }

            self::refreshInventory($route, $type, $criteria, $blocked);
            if ($blocked === null)
            {
                $targets[$route['id'] . ':' . $type] = ['route' => $route, 'type' => $type, 'criteria' => $criteria, 'mappings' => $mappings];
            }
        }
        return $targets;
    }

    public static function assetCriteria(string $type, int $entityId): ?array
    {
        global $CFG_GLPI, $DB;

        if (!in_array($type, $CFG_GLPI['asset_types'], true) || !is_subclass_of($type, CommonDBTM::class))
        {
            return null;
        }
        $columns = $DB->listFields($type::getTable());
        if (!isset($columns['serial'], $columns['entities_id'], $columns['name']))
        {
            return null;
        }

        // Match the mapped entity and exclude trashed assets and templates where applicable.
        $criteria = ['entities_id' => $entityId];
        foreach (['is_deleted', 'is_template'] as $flag)
        {
            if (isset($columns[$flag]))
            {
                $criteria[$flag] = 0;
            }
        }
        if (is_subclass_of($type, Asset::class) && isset($columns['assets_assetdefinitions_id']))
        {
            $criteria['assets_assetdefinitions_id'] = $type::getDefinition()->getID();
        }
        return $criteria;
    }

    private static function refreshInventory(array $route, string $type, array $criteria, ?string $blocked): void
    {
        global $DB;

        $table = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        $scope = ['routes_id' => $route['id'], 'asset_type' => $type];
        $previous = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'assets_id', 'serial', 'asset_name', 'status', 'last_error', 'pending_fields'],
            'FROM' => $table,
            'WHERE' => $scope,
        ]) as $entry)
        {
            $previous[(int) $entry['assets_id']] = $entry;
        }
        foreach ($DB->request(['SELECT' => ['id', 'name', 'serial'], 'FROM' => $type::getTable(), 'WHERE' => $criteria]) as $asset)
        {
            $assetId = (int) $asset['id'];
            $entry = $previous[$assetId] ?? null;
            unset($previous[$assetId]);
            $serial = trim((string) $asset['serial']);
            $state = ['serial' => $serial, 'asset_name' => (string) $asset['name']];
            $error = $serial === '' ? 'Enter a serial number in GLPI A so the sync can identify this asset on GLPI B.' : $blocked;

            if ($entry === null)
            {
                try
                {
                    // A manual request may already have indexed this asset since inventory started.
                    $insert = $DB->buildInsert($table, $scope + ['assets_id' => $assetId, 'last_error' => $error] + $state);
                    $DB->doQuery($insert . ' ON DUPLICATE KEY UPDATE id = id');
                }
                catch (RuntimeException $exception)
                {
                    // A cascade can remove the route after inventory reads it. Continue with other routes.
                    $routeStillExists = $DB->request([
                        'SELECT' => ['id'],
                        'FROM' => PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE,
                        'WHERE' => ['id' => $route['id']],
                    ])->current();
                    if ($routeStillExists)
                    {
                        throw $exception;
                    }
                    return;
                }
                continue;
            }
            if ($entry['serial'] !== $serial)
            {
                $status = $entry['status'] === 'cancelled' ? 'cancelled' : 'pending';
                $state += ['status' => $status, 'field_snapshot' => null, 'pending_fields' => null, 'sync_direction' => null,
                    'last_attempt' => null, 'last_error' => $error, 'last_error_details' => null];
            }
            elseif ($entry['status'] === 'pending' && $entry['last_error'] !== $error)
            {
                $state['last_error'] = $error;
            }
            if ($entry['asset_name'] !== $state['asset_name'] || array_key_exists('last_error', $state))
            {
                $DB->update($table, $state, [
                    'id' => $entry['id'], 'status' => $entry['status'], 'pending_fields' => $entry['pending_fields'],
                ]);
            }
        }

        // These entries are only a status index. Assets removed from A must not be processed.
        foreach ($previous as $entry)
        {
            $DB->delete($table, ['id' => $entry['id'], 'pending_fields' => $entry['pending_fields']]);
        }
    }

    public static function getConnection(array $route): ?array
    {
        global $DB;

        $currentRoute = $DB->request([
            'SELECT' => ['id'],
            'FROM' => PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE,
            'WHERE' => [
                'id' => $route['id'], 'is_enabled' => 1, 'connection_revision' => $route['connection_revision'],
                'connections_id' => $route['connections_id'], 'controller_entity_id' => $route['controller_entity_id'],
                'agent_entity_id' => $route['agent_entity_id'], 'asset_types' => $route['asset_types'],
            ],
        ])->current();
        if (!$currentRoute)
        {
            return null;
        }

        return $DB->request([
            'FROM' => PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE,
            'WHERE' => ['id' => $route['connections_id'], 'is_enabled' => 1, 'settings_revision' => $route['connection_revision']],
        ])->current() ?: null;
    }

    public static function getMappings(int $routeId, string $type): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM' => PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE,
            'WHERE' => ['routes_id' => $routeId, 'asset_type' => $type],
            'ORDER' => ['controller_option_id', 'agent_option_id'],
        ]), false);
    }
}
