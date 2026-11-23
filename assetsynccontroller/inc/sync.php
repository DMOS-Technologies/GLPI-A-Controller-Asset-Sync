<?php

use GlpiPlugin\Assetsynccontroller\Access;

use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Assetsynccontroller\ManualSync;
use GlpiPlugin\Assetsynccontroller\Menu;
use GlpiPlugin\Assetsynccontroller\SyncProblem;
use GlpiPlugin\Assetsynccontroller\SyncRunner;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

global $CFG_GLPI, $DB;

$syncUrl = $CFG_GLPI['root_doc'] . Menu::PAGE_PATH . '?section=sync-information';
$canRetrySync = Access::canUpdate('sync-information');

$syncStatuses = [
    'pending' => ['label' => 'Pending', 'badge' => 'bg-secondary-lt'],
    'queued' => ['label' => 'Queued', 'badge' => 'bg-yellow-lt'],
    'cancelled' => ['label' => 'Cancelled', 'badge' => 'bg-secondary-lt'],
    'processing' => ['label' => 'Processing in B', 'badge' => 'bg-blue-lt'],
    'finished' => ['label' => 'Finished Syncing', 'badge' => 'bg-green-lt'],
    'failed' => ['label' => 'Failed Syncing', 'badge' => 'bg-red-lt'],
];
$syncDirections = ['a_to_b' => 'A → B', 'b_to_a' => 'B → A', 'both' => 'A ↔ B'];

// The same entity scope applies to the route selector and its asset status records.
$routes = [];
$activeEntityIds = array_values(Session::getActiveEntities());
if ($activeEntityIds !== [])
{
    $routesTable = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
    $connectionsTable = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
    $entityTable = Entity::getTable();
    $routeRows = $DB->request([
        'SELECT' => [
            "$routesTable.id",
            "$routesTable.connection_name",
            "$routesTable.agent_entity_name",
            "$routesTable.asset_types",
            "$connectionsTable.name AS current_connection_name",
            "$entityTable.name AS controller_entity_name",
            "$entityTable.completename AS controller_entity_path",
        ],
        'FROM' => $routesTable,
        'INNER JOIN' => [$entityTable => ['ON' => [$routesTable => 'controller_entity_id', $entityTable => 'id']]],
        'LEFT JOIN' => [$connectionsTable => ['ON' => [$routesTable => 'connections_id', $connectionsTable => 'id']]],
        'WHERE' => ["$routesTable.controller_entity_id" => $activeEntityIds],
        'ORDER' => ["$routesTable.id"],
    ]);
    foreach ($routeRows as $routeRow)
    {
        $routes[(int) $routeRow['id']] = $routeRow;
    }
}

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$routeId = filter_var(($isPost ? $_POST : $_GET)['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$route = $routeId !== false ? ($routes[$routeId] ?? null) : null;
if ($isPost)
{
    // GLPI checks the form's CSRF token. The route lookup above also checks the current entity scope.
    Access::checkUpdate('sync-information');
    $action = $_POST['sync_action'] ?? null;
    if ($route !== null && $action === 'cancel')
    {
        if (($_POST['confirm_cancel'] ?? '') !== '1')
        {
            throw new BadRequestHttpException('Confirm that you want to cancel the queued assets.');
        }
        $cancelled = SyncRunner::cancelQueued($routeId);
        $message = match ($cancelled) {
            0 => 'No queued assets could be cancelled on this route.',
            1 => 'One queued asset was cancelled.',
            default => "$cancelled queued assets were cancelled.",
        };
        Session::addMessageAfterRedirect($message);
        Html::redirect($syncUrl . '&route_id=' . $routeId . '&sync_status=cancelled', 303);
    }
    if ($route !== null && $action === 'queue')
    {
        $scope = $_POST['sync_scope'] ?? null;
        $assetType = $_POST['asset_type'] ?? null;
        if (!is_string($scope) || !in_array($scope, ['all', 'type', 'asset'], true)
            || ($scope !== 'all' && !is_string($assetType)))
        {
            throw new BadRequestHttpException('Invalid manual sync request.');
        }
        try
        {
            $assetIds = null;
            if ($scope === 'asset')
            {
                // Validate the entire selection before queuing any asset.
                $input = $_POST['asset_ids'] ?? $_POST['asset_id'] ?? null;
                $limit = ManualSync::MAX_ASSET_IDS;
                if (!is_string($input) || strlen($input) > 16000)
                {
                    throw new SyncProblem("Enter up to $limit positive GLPI A asset IDs.");
                }
                $assetIds = [];
                foreach (preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY) as $part)
                {
                    $id = filter_var(ltrim($part, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($id === false || !ctype_digit($part))
                    {
                        throw new SyncProblem('Separate positive asset IDs with commas, spaces, or new lines.');
                    }
                    $assetIds[$id] = $id;
                }
                if ($assetIds === [] || count($assetIds) > $limit)
                {
                    throw new SyncProblem("Enter between 1 and $limit unique GLPI A asset IDs.");
                }
                $assetIds = array_values($assetIds);
            }
            $queued = ManualSync::queue($routeId, $scope, $scope === 'all' ? null : $assetType, $assetIds);
            $message = match ($queued) {
                0 => 'No eligible assets were found for this request.',
                1 => 'One asset queued for scheduled sync.',
                default => "$queued assets queued for scheduled sync.",
            };
            if ($assetIds !== null && $queued < count($assetIds))
            {
                $skipped = count($assetIds) - $queued;
                $message .= " Skipped $skipped IDs that were already processing or ineligible for this route and asset type.";
            }
            Session::addMessageAfterRedirect($message);
        }
        catch (SyncProblem $problem)
        {
            Session::addMessageAfterRedirect($problem->getMessage(), false, ERROR);
        }
        Html::redirect($syncUrl . '&route_id=' . $routeId, 303);
    }

    $syncId = filter_var($_POST['sync_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($route === null || !in_array($action, ['retry', 'retry_failed'], true) || ($action === 'retry' && $syncId === false))
    {
        throw new BadRequestHttpException('Invalid retry request.');
    }

    // Keep the failed attempt's pending field selection and only requeue rows still failed.
    $retryCriteria = ['routes_id' => $routeId, 'status' => 'failed'];
    if ($action === 'retry')
    {
        $retryCriteria['id'] = $syncId;
    }
    $DB->update(PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, [
        'status' => 'queued',
        'last_error' => null,
        'last_error_details' => null,
        'last_attempt' => null,
    ], $retryCriteria);
    $queued = $DB->affectedRows();
    $message = match ($queued) {
        0 => 'No failed assets were available to queue.',
        1 => 'One failed asset queued for the next scheduled sync.',
        default => "$queued failed assets queued for the next scheduled sync.",
    };
    Session::addMessageAfterRedirect($message);
    Html::redirect($syncUrl . '&route_id=' . $routeId, 303);
}
$statusFilter = $_GET['sync_status'] ?? '';
if (!is_string($statusFilter) || !isset($syncStatuses[$statusFilter]))
{
    $statusFilter = '';
}

$syncRows = [];
$syncStatusCounts = array_fill_keys(array_keys($syncStatuses), 0);
$syncAssetTotal = 0;
$syncAssetsLeft = 0;
if ($route !== null)
{
    // Summarize the whole selected route so table filters and pagination do not change progress.
    foreach ($DB->request([
        'SELECT' => ['status'],
        'COUNT' => 'asset_count',
        'FROM' => PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE,
        'WHERE' => ['routes_id' => $routeId],
        'GROUPBY' => ['status'],
    ]) as $statusCount)
    {
        $count = (int) $statusCount['asset_count'];
        $syncStatusCounts[$statusCount['status']] = $count;
        $syncAssetTotal += $count;
    }
    $syncAssetsLeft = $syncAssetTotal - $syncStatusCounts['finished'];
}
$canCancelQueuedAssets = $route !== null && $canRetrySync;
$assetTypes = $route === null ? [] : (json_decode($route['asset_types'], true) ?? []);
$mappedAssetTypes = [];
if ($route !== null && $canRetrySync)
{
    foreach ($DB->request([
        'SELECT' => ['asset_type'],
        'FROM' => PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE,
        'WHERE' => ['routes_id' => $routeId],
        'GROUPBY' => ['asset_type'],
    ]) as $mapping)
    {
        $type = $mapping['asset_type'];
        if (isset($assetTypes[$type]))
        {
            $mappedAssetTypes[$type] = $assetTypes[$type];
        }
    }
}
$assetTypeFilter = $_GET['sync_asset_type'] ?? '';
if (!is_string($assetTypeFilter) || !isset($assetTypes[$assetTypeFilter]))
{
    $assetTypeFilter = '';
}

$searchText = $_GET['sync_search'] ?? '';
$searchText = is_string($searchText) ? mb_substr(trim($searchText), 0, 255) : '';
$previousPageUrl = null;
$nextPageUrl = null;
if ($route !== null)
{
    $criteria = ['routes_id' => $routeId];
    if ($statusFilter !== '')
    {
        $criteria['status'] = $statusFilter;
    }
    if ($assetTypeFilter !== '')
    {
        $criteria['asset_type'] = $assetTypeFilter;
    }
    if ($searchText !== '')
    {
        // Treat wildcard characters in a name or serial number as literal text.
        $pattern = DBmysql::quoteValue('%' . strtr($searchText, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
        $criteria['OR'] = [
            new QueryExpression("asset_name LIKE $pattern ESCAPE '!'"),
            new QueryExpression("serial LIKE $pattern ESCAPE '!'"),
        ];
    }

    // Limit the page size without hiding older records. The extra row tells us if there is a next page.
    $pageSize = 50;
    $page = filter_var($_GET['sync_page'] ?? 1, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $pageSize)],
    ]) ?: 1;
    $syncColumns = ['id', 'asset_type', 'assets_id', 'serial', 'asset_name', 'status', 'last_error', 'sync_direction', 'date_mod'];
    // Failure details can contain a submitted asset value. Only users who can retry sync may see it.
    if ($canRetrySync)
    {
        $syncColumns[] = 'last_error_details';
    }
    $syncRows = iterator_to_array($DB->request([
        'SELECT' => $syncColumns,
        'FROM' => PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE,
        'WHERE' => $criteria,
        // Sort before pagination so finished assets don't appear ahead of unfinished work on later pages.
        'ORDER' => [new QueryExpression("CASE WHEN status = 'finished' THEN 1 ELSE 0 END"), 'date_mod DESC', 'asset_type', 'assets_id'],
        'START' => ($page - 1) * $pageSize,
        'LIMIT' => $pageSize + 1,
    ]), false);

    $pageQuery = [
        'section' => 'sync-information',
        'route_id' => $routeId,
        'sync_status' => $statusFilter,
        'sync_search' => $searchText,
        'sync_asset_type' => $assetTypeFilter,
    ];
    if ($page > 1)
    {
        $previousPageUrl = '?' . http_build_query($pageQuery + ['sync_page' => $page - 1]);
    }
    if (count($syncRows) > $pageSize)
    {
        array_pop($syncRows);
        $nextPageUrl = '?' . http_build_query($pageQuery + ['sync_page' => $page + 1]);
    }
}

$retryToken = $canRetrySync ? Session::getNewCSRFToken() : '';
