<?php

use GlpiPlugin\Assetsynccontroller\Menu;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Check permission for direct visits as well as visits from the Setup menu.
Session::checkRight(Config::$rightname, READ);

// A saved link may still be visited when GLPI has not loaded the plugin.
$pluginIsLoaded = defined('PLUGIN_ASSETSYNCCONTROLLER_NAME');
if (!$pluginIsLoaded || !Plugin::isPluginActive('assetsynccontroller'))
{
    throw new NotFoundHttpException();
}

// Each section has a stable URL key and a label displayed in the menu.
$sections = [
    'connections' => 'Connections',
    'routes' => 'Routes',
    'mappings' => 'Mappings',
    'sync-information' => 'Sync Information',
    'billing-configuration' => 'Billing Configuration',
];

// Open Connections by default, including when a link has an invalid section.
$selectedSection = $_GET['section'] ?? 'connections';
if (!is_string($selectedSection) || !isset($sections[$selectedSection]))
{
    $selectedSection = 'connections';
}

if ($selectedSection === 'connections')
{
    // Handle writes and redirects before GLPI sends the page header.
    require dirname(__DIR__) . '/inc/connections.php';
}
elseif ($selectedSection === 'routes')
{
    require dirname(__DIR__) . '/inc/routes.php';
}
elseif ($selectedSection === 'mappings')
{
    require dirname(__DIR__) . '/inc/mappings.php';
}
elseif ($selectedSection === 'sync-information')
{
    require dirname(__DIR__) . '/inc/sync.php';
}
elseif ($selectedSection === 'billing-configuration')
{
    require dirname(__DIR__) . '/inc/billing.php';
}

Html::header(PLUGIN_ASSETSYNCCONTROLLER_NAME, '', 'config', Menu::class);
?>

<nav class="nav nav-tabs flex-wrap" aria-label="Asset Sync Controller sections">
    <?php foreach ($sections as $sectionKey => $sectionLabel): ?>
        <?php $isSelectedSection = ($sectionKey === $selectedSection); ?>
        <a class="nav-link<?= $isSelectedSection ? ' active' : '' ?>"
           href="?section=<?= htmlescape($sectionKey) ?>"<?= $isSelectedSection ? ' aria-current="page"' : '' ?>>
            <?= htmlescape($sectionLabel) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php
if ($selectedSection === 'connections')
{
    require dirname(__DIR__) . '/templates/connections.php';
}
elseif ($selectedSection === 'routes')
{
    require dirname(__DIR__) . '/templates/routes.php';
}
elseif ($selectedSection === 'mappings')
{
    require dirname(__DIR__) . '/templates/mappings.php';
}
elseif ($selectedSection === 'sync-information')
{
    require dirname(__DIR__) . '/templates/sync.php';
}
elseif ($selectedSection === 'billing-configuration')
{
    require dirname(__DIR__) . '/templates/billing.php';
}

Html::footer();
