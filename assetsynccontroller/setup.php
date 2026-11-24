<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Assetsynccontroller\Menu;
use GlpiPlugin\Assetsynccontroller\Profile as ControllerProfile;

const PLUGIN_ASSETSYNCCONTROLLER_NAME = 'DMOS AssetSync - Master';
const PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE = 'glpi_plugin_assetsynccontroller_connections';
const PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE = 'glpi_plugin_assetsynccontroller_routes';
const PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE = 'glpi_plugin_assetsynccontroller_mappings';
const PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE = 'glpi_plugin_assetsynccontroller_syncitems';
const PLUGIN_ASSETSYNCCONTROLLER_CURSORS_TABLE = 'glpi_plugin_assetsynccontroller_synccursors';

function plugin_init_assetsynccontroller(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['assetsynccontroller'] = true;
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['assetsynccontroller'] = ['js/history-field.js'];
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['assetsynccontroller'] = [Menu::class, 'refreshMenu'];
    Plugin::registerClass(ControllerProfile::class, ['addtabon' => [Profile::class]]);

    // Include both tokens when GLPI rotates its encryption key.
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['assetsynccontroller'] = [
        PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE . '.application_token',
        PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE . '.user_token',
    ];

    // GLPI uses the internal name "config" for the Setup menu.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['assetsynccontroller'] = [
        'config' => Menu::class,
    ];
}

// GLPI reads this metadata when it discovers the plugin.
function plugin_version_assetsynccontroller(): array
{
    return [
        'name' => PLUGIN_ASSETSYNCCONTROLLER_NAME,
        'version' => '0.21.1',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '12.0.0',
            ],
        ],
    ];
}
