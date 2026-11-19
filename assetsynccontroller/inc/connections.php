<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use GlpiPlugin\Assetsynccontroller\AgentApi;
use GlpiPlugin\Assetsynccontroller\LocalAccount;
use GlpiPlugin\Assetsynccontroller\Menu;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

global $CFG_GLPI, $DB;

$connectionsUrl = $CFG_GLPI['root_doc'] . Menu::PAGE_PATH . '?section=connections';
$connectionsTable = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
$canManageConnections = Session::haveRight(Config::$rightname, UPDATE);
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$action = $isPost ? ($_POST['connection_action'] ?? '') : ($_GET['connection_action'] ?? '');
$connection = ['id' => 0, 'name' => '', 'api_url' => ''];
$error = '';

if ($isPost || $action !== '')
{
    Session::checkRight(Config::$rightname, UPDATE);

    $allowedActions = $isPost ? ['add', 'edit', 'delete', 'enable', 'disable', 'test', 'save_local_write_user'] : ['add', 'edit', 'delete'];
    if (!in_array($action, $allowedActions, true))
    {
        throw new BadRequestHttpException('Unknown connection action.');
    }

    if ($action !== 'add' && $action !== 'save_local_write_user')
    {
        $idInput = $isPost ? ($_POST['id'] ?? null) : ($_GET['id'] ?? null);
        $id = filter_var($idInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false)
        {
            throw new BadRequestHttpException('Invalid connection ID.');
        }

        // Only the server-side connection test needs the encrypted tokens.
        $fields = ['id', 'name', 'api_url'];
        if ($action === 'test')
        {
            $fields[] = 'application_token';
            $fields[] = 'user_token';
        }
        $rows = $DB->request([
            'SELECT' => $fields,
            'FROM' => $connectionsTable,
            'WHERE' => ['id' => $id],
        ]);
        if (count($rows) === 0)
        {
            throw new NotFoundHttpException('Connection not found.');
        }
        $connection = $rows->current();
    }
}

// GLPI validates the CSRF token before running this page's POST handler.
if ($isPost)
{
    if ($action === 'save_local_write_user')
    {
        $userId = filter_var($_POST['local_write_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($userId === false || ($userId > 0 && LocalAccount::findActiveUser($userId) === null))
        {
            Session::addMessageAfterRedirect('Choose an active GLPI A user.', false, ERROR);
        }
        else
        {
            // Zero clears the shared account.
            Config::setConfigurationValues(LocalAccount::CONFIG_CONTEXT, [LocalAccount::CONFIG_KEY => $userId]);
            Session::addMessageAfterRedirect('Global local account saved.');
        }
        Html::redirect($connectionsUrl, 303);
    }

    if ($action === 'test')
    {
        $agent = AgentApi::open(
            $connection['api_url'],
            $connection['application_token'],
            $connection['user_token'],
        );

        if ($agent?->close() === true)
        {
            // An in-flight test must only update the settings it actually tested.
            $testedSettings = ['id' => $connection['id']];
            foreach (['api_url', 'application_token', 'user_token'] as $field)
            {
                $testedSettings[] = ['RAW' => ['BINARY ' . DBmysql::quoteName($field) => $connection[$field]]];
            }
            $DB->update($connectionsTable, ['last_contact' => QueryFunction::now()], $testedSettings);
            Session::addMessageAfterRedirect('Connection is successful');
        }
        else
        {
            Session::addMessageAfterRedirect('Connection test failed. Check the Agent B REST API URL and tokens.', false, ERROR);
        }
        Html::redirect($connectionsUrl, 303);
    }

    if ($action === 'delete')
    {
        // The database also removes routes, field mappings, and sync status rows.
        $DB->delete($connectionsTable, ['id' => $connection['id']]);
        Html::redirect($connectionsUrl, 303);
    }

    if ($action === 'enable' || $action === 'disable')
    {
        // Set the requested state explicitly so a repeated request is safe.
        $DB->update($connectionsTable, ['is_enabled' => (int) ($action === 'enable')], ['id' => $connection['id']]);
        Html::redirect($connectionsUrl, 303);
    }

    $name = $_POST['name'] ?? '';
    $apiUrl = $_POST['api_url'] ?? '';
    $applicationToken = $_POST['application_token'] ?? '';
    $userToken = $_POST['user_token'] ?? '';
    $connection['name'] = is_string($name) ? trim($name) : '';
    $connection['api_url'] = is_string($apiUrl) ? trim($apiUrl) : '';

    $parsedUrl = filter_var($connection['api_url'], FILTER_VALIDATE_URL)
        ? parse_url($connection['api_url'])
        : false;

    if ($connection['name'] === '' || mb_strlen($connection['name']) > 255)
    {
        $error = 'Enter a connection name of up to 255 characters.';
    }
    elseif (
        !$parsedUrl
        || !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)
        || empty($parsedUrl['host'])
        || isset($parsedUrl['user']) || isset($parsedUrl['pass'])
        || isset($parsedUrl['query']) || isset($parsedUrl['fragment'])
        || strlen($connection['api_url']) > 2048
    )
    {
        $error = 'Enter an HTTP or HTTPS API base URL without credentials, a query, or a fragment.';
    }
    else
    {
        $values = ['name' => $connection['name'], 'api_url' => $connection['api_url']];

        // On edit, omit each blank token from the update to preserve the saved token.
        foreach (['application_token' => $applicationToken, 'user_token' => $userToken] as $column => $token)
        {
            if ($token === '' && $action === 'edit')
            {
                continue;
            }
            if (!is_string($token) || !preg_match('/\A[\x21-\x7e]{1,1024}\z/', $token))
            {
                $error = 'Enter valid application and user tokens. Tokens must contain 1–1024 printable characters without spaces.';
                break;
            }

            $values[$column] = (new GLPIKey())->encrypt($token);
            if ($values[$column] === '')
            {
                $error = 'GLPI could not encrypt the tokens. The connection was not saved.';
                break;
            }
        }
    }

    if ($error === '')
    {
        if ($action === 'add')
        {
            $DB->insert($connectionsTable, $values);
        }
        else
        {
            // Compare against the stored URL before replacing it in this same update.
            $lastContact = null;
            $settingsRevision = new QueryExpression('settings_revision + 1');
            if (!isset($values['application_token']) && !isset($values['user_token']))
            {
                $sameUrl = 'BINARY api_url = ' . DBmysql::quoteValue($connection['api_url']);
                $lastContact = new QueryExpression("CASE WHEN $sameUrl THEN last_contact ELSE NULL END");
                $settingsRevision = new QueryExpression("CASE WHEN $sameUrl THEN settings_revision ELSE settings_revision + 1 END");
            }
            $DB->update($connectionsTable, [
                'last_contact' => $lastContact,
                'settings_revision' => $settingsRevision,
            ] + $values, ['id' => $connection['id']]);
        }

        Html::redirect($connectionsUrl, 303);
    }
}

if ($action === '')
{
    if ($canManageConnections)
    {
        $localWriteUserId = (int) Config::getConfigurationValue(LocalAccount::CONFIG_CONTEXT, LocalAccount::CONFIG_KEY);
    }

    $connections = $DB->request([
        'SELECT' => ['id', 'name', 'is_enabled', 'last_contact'],
        'FROM' => $connectionsTable,
        'ORDER' => ['name', 'id'],
    ]);
}

// The row forms share one page token. Each successful POST loads a fresh page.
$csrfToken = $canManageConnections ? Session::getNewCSRFToken() : '';
