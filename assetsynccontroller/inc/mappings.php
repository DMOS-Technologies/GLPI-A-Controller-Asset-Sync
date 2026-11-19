<?php

use Glpi\Search\SearchOption;
use GlpiPlugin\Assetsynccontroller\AgentApi;
use GlpiPlugin\Assetsynccontroller\MappingFields;
use GlpiPlugin\Assetsynccontroller\Menu;
use GlpiPlugin\Assetsynccontroller\RouteSettings;
use GlpiPlugin\Assetsynccontroller\SyncFields;
use GlpiPlugin\Assetsynccontroller\SyncFlags;
use GlpiPlugin\Assetsynccontroller\SyncProblem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

global $CFG_GLPI, $DB;

$mappingsUrl = $CFG_GLPI['root_doc'] . Menu::PAGE_PATH . '?section=mappings';
$routesTable = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
$connectionsTable = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
$mappingsTable = PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE;
$entityTable = Entity::getTable();
$canManageMappings = Session::haveRight(Config::$rightname, UPDATE);
$directions = [
    'a_to_b' => 'A → B',
    'b_to_a' => 'B → A',
    'newer_edit' => 'Both A and B — Newer Edit Wins',
];
$flagLabels = ['is_asset' => 'Is Asset?', 'euc_services' => 'EUC Services'];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;
$action = $isPost ? ($input['mapping_action'] ?? '') : '';
if ($isPost)
{
    // GLPI verifies CSRF before this handler runs.
    Session::checkRight(Config::$rightname, UPDATE);
    if (!in_array($action, ['load', 'save', 'remove', 'save_flags', 'clear_flags'], true))
    {
        throw new BadRequestHttpException('Unknown mapping action.');
    }
}

$submittedFlags = [];
if ($action === 'save_flags')
{
    $submittedFlags = $input['agent_flag_id'] ?? [];
    if (!is_array($submittedFlags) || array_diff_key($submittedFlags, $flagLabels) !== [])
    {
        throw new BadRequestHttpException('Invalid GLPI B flags.');
    }
    if (array_filter($submittedFlags, static fn($value): bool => $value !== '') === [])
    {
        // Clearing optional flags requires no live connection to GLPI B.
        $action = 'clear_flags';
    }
}

// List only routes from the current GLPI entity scope. The selector never loads credentials.
$availableRoutes = [];
$activeEntityIds = array_values(Session::getActiveEntities());
if ($activeEntityIds !== [])
{
    $routeRows = $DB->request([
        'SELECT' => [
            "$routesTable.*",
            "$connectionsTable.name AS current_connection_name",
            "$connectionsTable.settings_revision AS current_connection_revision",
            "$entityTable.completename AS controller_entity_name",
        ],
        'FROM' => $routesTable,
        'INNER JOIN' => [$entityTable => ['ON' => [$routesTable => 'controller_entity_id', $entityTable => 'id']]],
        'LEFT JOIN' => [$connectionsTable => ['ON' => [$routesTable => 'connections_id', $connectionsTable => 'id']]],
        'WHERE' => ["$routesTable.controller_entity_id" => $activeEntityIds],
        'ORDER' => ["$routesTable.id"],
    ]);
    foreach ($routeRows as $row)
    {
        $availableRoutes[(int) $row['id']] = $row;
    }
}

$positiveId = ['options' => ['min_range' => 1]];
$routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT, $positiveId);
$route = $availableRoutes[$routeId] ?? null;
$routeAssetTypes = $route === null ? [] : json_decode($route['asset_types'], true);
$assetType = $input['asset_type'] ?? '';
$hasAssetType = is_string($assetType) && isset($routeAssetTypes[$assetType]);
$routeIsVerified = $route !== null && $route['current_connection_revision'] !== null
    && (int) $route['connection_revision'] === (int) $route['current_connection_revision'];

$error = '';
$controllerFields = [];
$agentFields = [];
$agentOptions = [];
$controllerOptionId = false;
$agentOptionId = false;
$direction = 'a_to_b';
$savedMappings = [];
$savedFlags = [];
$flagChoices = [];
$flagSelections = [];

if ($route !== null && $hasAssetType)
{
    $mappingKey = ['routes_id' => $routeId, 'asset_type' => $assetType];
    $selectionUrl = $mappingsUrl . '&' . http_build_query(
        ['route_id' => $routeId, 'asset_type' => $assetType],
        '',
        '&',
        PHP_QUERY_RFC3986,
    );
    $controllerOptionId = filter_var($input['controller_option_id'] ?? null, FILTER_VALIDATE_INT, $positiveId);
    $agentOptionId = filter_var($input['agent_option_id'] ?? null, FILTER_VALIDATE_INT, $positiveId);

    // Removing an obsolete local pair does not require contacting Agent B.
    if ($action === 'remove')
    {
        if ($controllerOptionId === false || $agentOptionId === false)
        {
            throw new BadRequestHttpException('Invalid field pair.');
        }
        try
        {
            RouteSettings::withLock($routeId, static function () use (
                $DB, $routesTable, $routeId, $activeEntityIds, $mappingsTable, $mappingKey, $controllerOptionId, $agentOptionId
            ): void
            {
                // The route may have moved to another entity while this request waited for a running sync.
                if (!$DB->request([
                    'SELECT' => ['id'], 'FROM' => $routesTable,
                    'WHERE' => ['id' => $routeId, 'controller_entity_id' => $activeEntityIds],
                ])->current())
                {
                    throw new SyncProblem('This route is no longer available in your current scope.');
                }
                $DB->delete($mappingsTable, $mappingKey + [
                    'controller_option_id' => $controllerOptionId,
                    'agent_option_id' => $agentOptionId,
                ]);
            }, 8);
            Session::addMessageAfterRedirect('Mapping removed.');
        }
        catch (SyncProblem $problem)
        {
            Session::addMessageAfterRedirect($problem->getMessage(), false, ERROR);
        }
        Html::redirect($selectionUrl, 303);
    }

    $savedMappings = $DB->request([
        'FROM' => $mappingsTable,
        'WHERE' => $mappingKey,
        'ORDER' => ['controller_option_id', 'agent_option_id'],
    ]);
    $savedFlags = SyncFlags::get($routeId, $assetType);
}

if ($isPost && !$hasAssetType)
{
    $error = 'Select an asset type from a route available in your current GLPI scope.';
}
elseif ($isPost && $action !== 'clear_flags')
{
    if (!$routeIsVerified)
    {
        $error = 'This route is Pending. Select a verified route to load fields.';
    }
    elseif (!in_array($assetType, $CFG_GLPI['asset_types'] ?? [], true) || !is_subclass_of($assetType, CommonDBTM::class))
    {
        $error = 'This asset type is unavailable on Controller A. Set up the same asset type on A before mapping its fields.';
    }
    else
    {
        $controllerFields = MappingFields::fromSearchOptions(SearchOption::getOptionsForItemtype($assetType));
        $connection = $DB->request([
            'SELECT' => ['api_url', 'application_token', 'user_token'],
            'FROM' => $connectionsTable,
            'WHERE' => ['id' => $route['connections_id'], 'settings_revision' => $route['connection_revision']],
        ])->current();
        if ($connection)
        {
            $agent = AgentApi::open($connection['api_url'], $connection['application_token'], $connection['user_token']);
            if ($agent !== null)
            {
                try
                {
                    $agentOptions = $agent->getFieldOptions($assetType) ?? [];
                    $agentFields = MappingFields::fromSearchOptions($agentOptions);
                }
                finally
                {
                    if (!$agent->close())
                    {
                        $agentFields = [];
                    }
                }
            }
        }

        if ($controllerFields === [] || $agentFields === [])
        {
            $error = 'Could not load mapping fields from both GLPI instances. Check the route, asset type, and Agent B API access.';
        }
        foreach ($agentFields as $id => $field)
        {
            $option = $agentOptions[$id];
            // Keep Fields Yes/No and the asset's own writable columns, not indirect search joins.
            $isFieldsFlag = ($option['pfields_type'] ?? '') === 'yesno' && ($option['massiveaction'] ?? true);
            $isNativeFlag = $field['table'] === $assetType::getTable()
                && (!empty($option['field_definition']) || SyncFields::nativeColumnCanWrite($field['field']));
            if (($field['datatype'] ?? '') === 'bool' && ($isFieldsFlag || $isNativeFlag))
            {
                $flagChoices[$id] = $field;
            }
        }
        foreach ($savedFlags as $key => $savedFlag)
        {
            $id = $savedFlag['id'];
            $discovered = $flagChoices[$id] ?? null;
            if ($discovered !== null && $discovered['table'] === $savedFlag['table']
                && $discovered['field'] === $savedFlag['field']
                && ($discovered['linkfield'] ?? null) === ($savedFlag['linkfield'] ?? null))
            {
                $flagSelections[$key] = $id;
            }
        }
    }
}

if ($action === 'save' && $error === '')
{
    // Recheck both field selections against the metadata loaded by this request.
    $direction = $input['direction'] ?? '';
    if ($controllerOptionId === false || $agentOptionId === false
        || !isset($controllerFields[$controllerOptionId], $agentFields[$agentOptionId]))
    {
        $error = 'Select an available field from both GLPI instances.';
    }
    elseif (!is_string($direction) || !isset($directions[$direction]))
    {
        $error = 'Select a mapping direction.';
    }
    else
    {
        $values = $mappingKey + [
            'controller_option_id' => $controllerOptionId,
            'agent_option_id' => $agentOptionId,
            'controller_field' => json_encode($controllerFields[$controllerOptionId], JSON_THROW_ON_ERROR),
            'agent_field' => json_encode($agentFields[$agentOptionId], JSON_THROW_ON_ERROR),
            'direction' => $direction,
        ];
    }
}

$flagsToSave = [];
if ($action === 'save_flags' && $error === '')
{
    $flagSelections = [];
    foreach ($flagLabels as $key => $label)
    {
        $submitted = $submittedFlags[$key] ?? '';
        if ($submitted === '')
        {
            continue;
        }
        $id = filter_var($submitted, FILTER_VALIDATE_INT, $positiveId);
        if ($id === false || !isset($flagChoices[$id]))
        {
            $error = "Select an available GLPI B Yes/No field for $label.";
            break;
        }
        $flagSelections[$key] = $id;
        $flagsToSave[$key] = ['id' => $id] + $flagChoices[$id];
    }
    if ($error === '' && isset($flagsToSave['is_asset'], $flagsToSave['euc_services'])
        && SyncFlags::sameField($flagsToSave['is_asset'], $flagsToSave['euc_services']))
    {
        $error = 'Select a different GLPI B field for each flag.';
    }
}

if (in_array($action, ['save', 'save_flags', 'clear_flags'], true) && $error === '')
{
    // Lock the route for mapping and flag edits so concurrent requests cannot reuse a destination.
    $DB->beginTransaction();
    try
    {
        $currentRoute = $DB->doQuery('SELECT connections_id, connection_revision, controller_entity_id, asset_types FROM '
            . DBmysql::quoteName($routesTable) . ' WHERE id = ' . $routeId . ' FOR UPDATE')->fetch_assoc();
        if (!$currentRoute || (int) $currentRoute['connections_id'] !== (int) $route['connections_id']
            || (int) $currentRoute['connection_revision'] !== (int) $route['connection_revision']
            || (int) $currentRoute['controller_entity_id'] !== (int) $route['controller_entity_id']
            || $currentRoute['asset_types'] !== $route['asset_types'])
        {
            $error = 'The route settings changed. Reload the route before saving.';
        }
        elseif ($action === 'clear_flags')
        {
            SyncFlags::save($routeId, $assetType, []);
        }
        elseif ($action === 'save_flags')
        {
            foreach ($DB->request(['FROM' => $mappingsTable, 'WHERE' => $mappingKey]) as $mapping)
            {
                $destination = ['id' => (int) $mapping['agent_option_id']]
                    + json_decode($mapping['agent_field'], true, flags: JSON_THROW_ON_ERROR);
                foreach ($flagsToSave as $flag)
                {
                    if (SyncFlags::sameField($flag, $destination))
                    {
                        $error = 'A selected GLPI B flag field is already used by a regular mapping.';
                        break 2;
                    }
                }
            }
            if ($error === '')
            {
                SyncFlags::save($routeId, $assetType, $flagsToSave);
            }
        }
        else
        {
            $destination = ['id' => $agentOptionId] + $agentFields[$agentOptionId];
            foreach (SyncFlags::get($routeId, $assetType) as $flag)
            {
                if (SyncFlags::sameField($flag, $destination))
                {
                    $error = 'The selected GLPI B field is already configured as a flag.';
                    break;
                }
            }
            if ($error === '')
            {
                // Saving the same pair changes its direction instead of creating a duplicate.
                $DB->doQuery($DB->buildInsert($mappingsTable, $values) . ' ON DUPLICATE KEY UPDATE '
                    . 'controller_field = VALUES(controller_field), agent_field = VALUES(agent_field), direction = VALUES(direction)');
            }
        }
        $DB->commit();
    }
    catch (Throwable $exception)
    {
        $DB->rollBack();
        throw $exception;
    }

    if ($error === '')
    {
        Session::addMessageAfterRedirect(match ($action) {
            'clear_flags' => 'GLPI B flags cleared.',
            'save_flags' => 'GLPI B flags saved.',
            default => 'Mapping saved.',
        });
        Html::redirect($selectionUrl, 303);
    }
}

$csrfToken = $canManageMappings ? Session::getNewCSRFToken() : '';
