<?php

namespace GlpiPlugin\Assetsynccontroller;

use Session;
use Throwable;

class ManualSync
{
    public const MAX_ASSET_IDS = 500;

    public static function queue(int $routeId, string $scope, ?string $assetType = null, int|array|null $assetId = null): int
    {
        global $DB;

        if (!in_array($scope, ['all', 'type', 'asset'], true))
        {
            throw new SyncProblem('Select a valid manual sync action.');
        }

        $assetIds = [];
        if ($scope === 'asset')
        {
            // Single and bulk requests share the same route and asset eligibility checks.
            $assetIds = is_int($assetId) ? [$assetId] : $assetId;
            if ($assetIds === null || $assetIds === [] || !array_is_list($assetIds))
            {
                throw new SyncProblem('Enter one or more valid GLPI A asset IDs.');
            }
            foreach ($assetIds as $id)
            {
                if (!is_int($id) || $id <= 0)
                {
                    throw new SyncProblem('Enter one or more valid GLPI A asset IDs.');
                }
            }
            $assetIds = array_values(array_unique($assetIds));
            if (count($assetIds) > self::MAX_ASSET_IDS)
            {
                throw new SyncProblem('Enter at most ' . self::MAX_ASSET_IDS . ' asset IDs at a time.');
            }
        }

        // Check entity access again using the full route settings needed by the scheduled runner.
        $entityIds = array_values(Session::getActiveEntities());
        $route = $entityIds === [] ? null : $DB->request([
            'FROM' => PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE,
            'WHERE' => ['id' => $routeId, 'controller_entity_id' => $entityIds],
        ])->current();
        if (!$route || SyncRunner::getConnection($route) === null)
        {
            throw new SyncProblem('Select an enabled, verified route with an enabled connection.');
        }

        $assetTypes = json_decode($route['asset_types'], true) ?? [];
        if ($scope !== 'all')
        {
            if (!isset($assetTypes[$assetType]))
            {
                throw new SyncProblem('Select an asset type mapped on this route.');
            }
            $assetTypes = [$assetType => $assetTypes[$assetType]];
        }

        // A new request can arrive while cron is working on the same asset. The token distinguishes them.
        $requestToken = bin2hex(random_bytes(8));
        $queued = 0;
        $DB->beginTransaction();
        try
        {
            foreach (array_keys($assetTypes) as $type)
            {
                $mappings = SyncRunner::getMappings($routeId, $type);
                $criteria = SyncRunner::assetCriteria($type, (int) $route['controller_entity_id']);
                if ($mappings === [] || $criteria === null)
                {
                    if ($scope !== 'all')
                    {
                        throw new SyncProblem('Select an asset type mapped on this route.');
                    }
                    continue;
                }

                if ($scope === 'asset')
                {
                    $criteria['id'] = $assetIds;
                }

                $fieldIds = array_map('intval', array_column($mappings, 'controller_option_id'));
                $pendingFields = json_encode([
                    'a' => array_values(array_unique($fieldIds)),
                    'manual' => $requestToken,
                ], JSON_THROW_ON_ERROR);

                $assets = $DB->request([
                    'SELECT' => ['id', 'name', 'serial'],
                    'FROM' => $type::getTable(),
                    'WHERE' => $criteria,
                    'ORDER' => ['id'],
                ]);
                foreach ($assets as $asset)
                {
                    $serial = trim((string) $asset['serial']);
                    if ($serial === '')
                    {
                        continue;
                    }

                    $insert = $DB->buildInsert(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, [
                        'routes_id' => $routeId,
                        'asset_type' => $type,
                        'assets_id' => $asset['id'],
                        'serial' => $serial,
                        'asset_name' => (string) $asset['name'],
                        'status' => 'queued',
                        'pending_fields' => $pendingFields,
                    ]);
                    // Leave an in-flight request alone. Update status last so the other assignments see the original state.
                    $DB->doQuery($insert . " ON DUPLICATE KEY UPDATE
                        pending_fields = IF(status = 'processing', pending_fields, VALUES(pending_fields)),
                        last_error = IF(status = 'processing', last_error, NULL),
                        last_error_details = IF(status = 'processing', last_error_details, NULL),
                        last_attempt = IF(status = 'processing', last_attempt, NULL),
                        field_snapshot = IF(status = 'processing' OR BINARY serial = BINARY VALUES(serial), field_snapshot, NULL),
                        sync_direction = IF(status = 'processing' OR BINARY serial = BINARY VALUES(serial), sync_direction, NULL),
                        serial = IF(status = 'processing', serial, VALUES(serial)),
                        asset_name = IF(status = 'processing', asset_name, VALUES(asset_name)),
                        status = IF(status = 'processing', status, 'queued')");
                    if ($DB->affectedRows() > 0)
                    {
                        ++$queued;
                    }
                }
            }
            $DB->commit();
        }
        catch (Throwable $error)
        {
            $DB->rollBack();
            throw $error;
        }

        return $queued;
    }
}
