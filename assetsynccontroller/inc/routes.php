<?php

use GlpiPlugin\Assetsynccontroller\AgentApi;
use GlpiPlugin\Assetsynccontroller\Menu;
use GlpiPlugin\Assetsynccontroller\RouteSettings;
use GlpiPlugin\Assetsynccontroller\SyncProblem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

global $CFG_GLPI, $DB;

$routesUrl = $CFG_GLPI['root_doc'] . Menu::PAGE_PATH . '?section=routes';
$routesTable = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
$connectionsTable = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
$canManageRoutes = Session::haveRight(Config::$rightname, UPDATE);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$input = $isPost ? $_POST : $_GET;
$action = $input['route_action'] ?? '';
if ($isPost || $action !== '')
{
    Session::checkRight(Config::$rightname, UPDATE);
    $allowedActions = $isPost ? ['load', 'save', 'delete'] : ['add', 'edit', 'delete'];
    if (!in_array($action, $allowedActions, true))
    {
        throw new BadRequestHttpException('Unknown route action.');
    }
}

// Use the current GLPI entity scope for both listing and saving routes.
$controllerEntities = [];
$activeEntityIds = array_values(Session::getActiveEntities());
if ($activeEntityIds !== [])
{
    $entityRows = $DB->request([
        'SELECT' => ['id', 'name', 'completename'],
        'FROM' => Entity::getTable(),
        'WHERE' => ['id' => $activeEntityIds],
        'ORDER' => ['completename'],
    ]);
    foreach ($entityRows as $row)
    {
        $controllerEntities[(int) $row['id']] = $row['completename'] ?: $row['name'];
    }
}

$error = '';
$agentOptions = null;
$connection = null;
$selectedConnectionId = false;
$controllerEntityId = false;
$agentEntityId = false;
$selectedAssetTypes = [];
$isEnabled = '0';
$editingRoute = null;
$routeToDelete = null;
$routeId = null;
$routeToken = '';

if (in_array($action, ['edit', 'delete'], true)
    || ($isPost && array_key_exists('route_id', $input)))
{
    $routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($routeId === false || $controllerEntities === [])
    {
        throw new NotFoundHttpException('Route not found.');
    }
    $currentRoute = $DB->request([
        'FROM' => $routesTable,
        'WHERE' => ['id' => $routeId, 'controller_entity_id' => array_keys($controllerEntities)],
    ])->current();
    if (!$currentRoute)
    {
        throw new NotFoundHttpException('Route not found.');
    }

    // Detect another administrator's edit while this form was open.
    $routeToken = hash('sha256', json_encode($currentRoute, JSON_THROW_ON_ERROR));
    if ($isPost && $routeToken !== ($input['route_token'] ?? null))
    {
        $error = 'The route changed while the form was open. Review the current settings and try again.';
    }
    if ($action === 'delete')
    {
        $routeToDelete = $currentRoute;
    }
    else
    {
        $editingRoute = $currentRoute;
        $selectedConnectionId = isset($currentRoute['connections_id']) ? (int) $currentRoute['connections_id'] : false;
        $controllerEntityId = (int) $currentRoute['controller_entity_id'];
        $agentEntityId = (int) $currentRoute['agent_entity_id'];
        $selectedAssetTypes = array_keys(json_decode($currentRoute['asset_types'], true, flags: JSON_THROW_ON_ERROR));
        $isEnabled = (string) $currentRoute['is_enabled'];
    }
}

if ($isPost && $action === 'delete')
{
    if (($input['confirm_delete'] ?? '') !== '1')
    {
        throw new BadRequestHttpException('Confirm route deletion.');
    }
    if ($error === '')
    {
        try
        {
            if (RouteSettings::delete($routeToDelete))
            {
                Session::addMessageAfterRedirect('Route and related mappings deleted.');
                Html::redirect($routesUrl, 303);
            }
            $error = 'The route changed before it could be deleted. Reload the route and try again.';
        }
        catch (SyncProblem $problem)
        {
            $error = $problem->getMessage();
        }
    }
}

// Loading options and rechecking them on save both require a user-triggered POST.
if ($isPost && $action !== 'delete' && $error === '')
{
    $selectedConnectionId = filter_var($_POST['connection_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($editingRoute && $selectedConnectionId !== (int) $editingRoute['connections_id'])
    {
        // Entity IDs on different GLPI connections do not identify the same entity.
        $agentEntityId = false;
    }
    if ($selectedConnectionId !== false)
    {
        $connection = $DB->request([
            'SELECT' => ['id', 'name', 'api_url', 'application_token', 'user_token', 'settings_revision'],
            'FROM' => $connectionsTable,
            'WHERE' => ['id' => $selectedConnectionId],
        ])->current();
    }

    if (!$connection)
    {
        $error = 'Select a saved connection.';
    }
    else
    {
        $agent = AgentApi::open($connection['api_url'], $connection['application_token'], $connection['user_token']);
        if ($agent !== null)
        {
            try
            {
                $agentOptions = $agent->getRouteOptions();
            }
            finally
            {
                if (!$agent->close())
                {
                    $agentOptions = null;
                }
            }
        }
        if ($agentOptions === null)
        {
            $error = 'Could not load options from Agent B. Check the saved connection and Agent B API access.';
        }
        elseif ($agentOptions['entities'] === [] || $agentOptions['asset_types'] === [])
        {
            $agentOptions = null;
            $error = 'Agent B did not return any selectable entities or asset types.';
        }
    }
}

if ($action === 'save' && $agentOptions !== null)
{
    // Entity 0 is GLPI's root entity and is a valid selection on both sides.
    $entityIdOptions = ['options' => ['min_range' => 0]];
    $controllerEntityId = filter_var($_POST['controller_entity_id'] ?? null, FILTER_VALIDATE_INT, $entityIdOptions);
    $agentEntityId = filter_var($_POST['agent_entity_id'] ?? null, FILTER_VALIDATE_INT, $entityIdOptions);
    $isEnabled = $_POST['is_enabled'] ?? '0';
    $selectedAssetTypes = $_POST['asset_types'] ?? [];
    $validAssetTypes = [];

    if (is_array($selectedAssetTypes))
    {
        foreach ($selectedAssetTypes as $itemType)
        {
            if (!is_string($itemType) || !isset($agentOptions['asset_types'][$itemType]))
            {
                $validAssetTypes = [];
                break;
            }
            $validAssetTypes[$itemType] = $agentOptions['asset_types'][$itemType];
        }
    }

    if ($controllerEntityId === false || !isset($controllerEntities[$controllerEntityId]))
    {
        $error = 'Select a Controller A entity available in your current GLPI scope.';
    }
    elseif ($agentEntityId === false || !isset($agentOptions['entities'][$agentEntityId]))
    {
        $error = 'Select an entity available to the Agent B account.';
    }
    elseif ($validAssetTypes === [])
    {
        $error = 'Select at least one asset type from Agent B’s current catalog.';
    }
    elseif (!in_array($isEnabled, ['0', '1'], true))
    {
        $error = 'Select Enabled or Disabled for the route.';
    }
    else
    {
        // Store the revision that was actually checked. A later edit makes this route Pending.
        $values = [
            'connections_id' => $connection['id'],
            'connection_name' => $connection['name'],
            'connection_revision' => $connection['settings_revision'],
            'controller_entity_id' => $controllerEntityId,
            'agent_entity_id' => $agentEntityId,
            'agent_entity_name' => $agentOptions['entities'][$agentEntityId],
            'asset_types' => json_encode($validAssetTypes, JSON_THROW_ON_ERROR),
            'is_enabled' => (int) $isEnabled,
        ];
        if ($editingRoute === null)
        {
            $DB->insert($routesTable, $values);
            Session::addMessageAfterRedirect('Route added.');
            Html::redirect($routesUrl, 303);
        }
        try
        {
            if (RouteSettings::update($editingRoute, $values))
            {
                Session::addMessageAfterRedirect('Route updated.');
                Html::redirect($routesUrl, 303);
            }
            $error = 'The route or connection changed before the update was saved. Reload the route and try again.';
        }
        catch (SyncProblem $problem)
        {
            $error = $problem->getMessage();
        }
    }
}

if ($action === '')
{
    $routes = [];
    if ($controllerEntities !== [])
    {
        $routes = $DB->request([
            'SELECT' => ["$routesTable.*", "$connectionsTable.name AS current_connection_name",
                "$connectionsTable.settings_revision AS current_connection_revision"],
            'FROM' => $routesTable,
            'LEFT JOIN' => [
                $connectionsTable => ['ON' => [$routesTable => 'connections_id', $connectionsTable => 'id']],
            ],
            'WHERE' => ["$routesTable.controller_entity_id" => array_keys($controllerEntities)],
            'ORDER' => ["$routesTable.id"],
        ]);
    }
}
elseif ($routeToDelete === null)
{
    // The selector only needs display names and IDs.
    $savedConnections = $DB->request([
        'SELECT' => ['id', 'name'],
        'FROM' => $connectionsTable,
        'ORDER' => ['name', 'id'],
    ]);
}
$csrfToken = $canManageRoutes ? Session::getNewCSRFToken() : '';
