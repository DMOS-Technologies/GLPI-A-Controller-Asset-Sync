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
            'last_error' => 'The previous sync run ended before Agent B acknowledged the result.',
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
        $attempted = 0;
        $started = hrtime(true);
        foreach ($batch as $entry)
        {
            // Keep slow connections from holding up the minute worker for an entire large batch.
            if (hrtime(true) - $started >= 50_000_000_000)
            {
                break;
            }
            ++$attempted;
            $target = $targets[$entry['routes_id'] . ':' . $entry['asset_type']];
            // Check the queue status and request together. A cancelled or replaced row cannot dispatch.
            $where = [
                'id' => $entry['id'],
                'serial' => $entry['serial'],
                'status' => 'queued',
                'pending_fields' => $entry['pending_fields'],
            ];
            try
            {
                $route = $target['route'];
                $connection = self::getConnection($route);
                if ($connection === null)
                {
                    throw new SyncProblem('The route or connection is disabled or its settings changed.');
                }

                $type = $entry['asset_type'];
                $asset = new $type();
                if (!$asset->getFromDB($entry['assets_id'])
                    || (int) $asset->fields['entities_id'] !== (int) $route['controller_entity_id']
                    || !empty($asset->fields['is_deleted']) || !empty($asset->fields['is_template'])
                    || trim((string) $asset->fields['serial']) !== $entry['serial'])
                {
                    throw new SyncProblem('The asset or its serial number changed on GLPI A.');
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
                    throw new SyncProblem('The serial number is ambiguous in the mapped GLPI A entity.');
                }

                $agent = self::openAgent($connection, $agents);
                if ($agent === null)
                {
                    throw new SyncProblem('Could not authenticate to Agent B.');
                }

                $flags = SyncFlags::get((int) $route['id'], $type);
                $DB->update($syncTable, [
                    'status' => 'processing',
                    'last_attempt' => QueryFunction::now(),
                ], $where);
                if ($DB->affectedRows() === 0)
                {
                    --$attempted;
                    continue;
                }
                $where['status'] = 'processing';
                $result = SyncPlan::process($agent, $asset, $route, $target['mappings'], $entry, $flags);
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
                $DB->update($syncTable, $status, $where);
                if ($DB->affectedRows() === 0)
                {
                    // Keep the completed transfer's baseline if another request queued the same serial.
                    unset($status['status'], $status['last_error'], $status['last_error_details'], $status['pending_fields']);
                    $serial = \DBmysql::quoteValue($entry['serial']);
                    $DB->update($syncTable, $status, [
                        'id' => $entry['id'],
                        'status' => 'queued',
                        new QueryExpression("BINARY serial = BINARY $serial"),
                    ]);
                }
            }
            catch (SyncProblem $problem)
            {
                $DB->update($syncTable, [
                    'status' => 'failed',
                    'last_attempt' => QueryFunction::now(),
                    'last_error' => $problem->getMessage(),
                    'last_error_details' => $problem->details === [] ? null
                        : json_encode($problem->details, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
                ], $where);
            }
            catch (SyncException $problem)
            {
                $message = match ($problem->getMessage()) {
                    'field_unavailable', 'field_read_only' => 'GLPI A could not read or write a mapped field.',
                    'reference_unavailable', 'invalid_reference_value' => 'GLPI A could not resolve a mapped dropdown value in the route entity.',
                    'invalid_field_value' => 'A mapped value is incompatible with the destination field type.',
                    'notes_write_failed' => 'GLPI A did not retain a copied Notes record.',
                    default => 'GLPI A could not apply a mapped field value.',
                };
                $DB->update($syncTable, [
                    'status' => 'failed', 'last_attempt' => QueryFunction::now(),
                    'last_error' => $message, 'last_error_details' => null,
                ], $where);
            }
            catch (Throwable)
            {
                // Database and HTTP exception messages can contain asset data or credentials.
                $DB->update($syncTable, [
                    'status' => 'failed',
                    'last_attempt' => QueryFunction::now(),
                    'last_error' => 'GLPI could not apply the asset changes.',
                    'last_error_details' => null,
                ], $where);
            }
        }
        return $attempted;
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
                $blocked = 'The route is disabled.';
            }
            elseif (!$route['connection_enabled'] || (int) $route['connection_revision'] !== (int) $route['current_revision'])
            {
                $blocked = 'The connection is disabled or the route is Pending.';
            }
            elseif ($mappings === [])
            {
                $blocked = 'No fields are mapped for this asset type.';
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
            $error = $serial === '' ? 'The asset has no serial number.' : $blocked;

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
