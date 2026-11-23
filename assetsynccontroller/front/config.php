<?php

use GlpiPlugin\Assetsynccontroller\Access;
use GlpiPlugin\Assetsynccontroller\Menu;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// A saved link may still be visited when GLPI has not loaded the plugin.
$pluginIsLoaded = defined('PLUGIN_ASSETSYNCCONTROLLER_NAME');
if (!$pluginIsLoaded || !Plugin::isPluginActive('assetsynccontroller'))
{
    throw new NotFoundHttpException();
}

// Load any profile changes before choosing the first accessible Setup section.
Session::checkCentralAccess();
$sections = Access::visibleSections();
$selectedSection = $_GET['section'] ?? array_key_first($sections);
if ($selectedSection === null)
{
    throw new AccessDeniedHttpException('Your profile has no access to Asset Sync Controller.');
}
if (!is_string($selectedSection) || !isset(Access::SECTIONS[$selectedSection]))
{
    throw new NotFoundHttpException('Asset Sync Controller section not found.');
}
// Check explicit links as well as visible tabs.
Access::checkRead($selectedSection);

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
    if (isset($syncExportResponse))
    {
        return $syncExportResponse;
    }
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
