<?php

use GlpiPlugin\Assetsynccontroller\Access;
use GlpiPlugin\Assetsynccontroller\BillingConfiguration;
use GlpiPlugin\Assetsynccontroller\LocalAccount;
use GlpiPlugin\Assetsynccontroller\SyncFlags;
use GlpiPlugin\Assetsynccontroller\SyncRunner;
use GlpiPlugin\Assetsynccontroller\SyncTask;

function plugin_assetsynccontroller_install(): bool
{
    global $DB;

    $table = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
    $sign = DBConnection::getDefaultPrimaryKeySignOption();
    $charset = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();

    // The same statement can run during a fresh install or a plugin update.
    $DB->doQuery("
        CREATE TABLE IF NOT EXISTS `$table` (
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `api_url` varchar(2048) NOT NULL,
            `application_token` text NOT NULL,
            `user_token` text NOT NULL,
            `is_enabled` tinyint NOT NULL DEFAULT 0,
            `last_contact` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
    ");

    // Existing connections start on revision 1. Editing credentials or the API URL bumps it.
    if (!$DB->fieldExists($table, 'settings_revision', false))
    {
        $DB->doQuery("ALTER TABLE `$table` ADD `settings_revision` int unsigned NOT NULL DEFAULT 1");
    }

    $routesTable = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
    $DB->doQuery("
        CREATE TABLE IF NOT EXISTS `$routesTable` (
            `id` int $sign NOT NULL AUTO_INCREMENT,
            `connections_id` int $sign DEFAULT NULL,
            `connection_name` varchar(255) NOT NULL,
            `connection_revision` int unsigned NOT NULL,
            `controller_entity_id` int $sign NOT NULL,
            `agent_entity_id` int $sign NOT NULL,
            `agent_entity_name` text NOT NULL,
            `asset_types` text NOT NULL,
            `is_enabled` tinyint NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `controller_entity_id` (`controller_entity_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
    ");

    $mappingsTable = PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE;
    // Each field pair has its own direction. The unique key also lets users update that direction.
    $DB->doQuery("
        CREATE TABLE IF NOT EXISTS `$mappingsTable` (
            routes_id int $sign NOT NULL,
            asset_type varchar(255) NOT NULL,
            controller_option_id int unsigned NOT NULL,
            agent_option_id int unsigned NOT NULL,
            controller_field text NOT NULL,
            agent_field text NOT NULL,
            direction varchar(20) NOT NULL,
            PRIMARY KEY (routes_id, asset_type, controller_option_id, agent_option_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
    ");

    SyncFlags::install();
    return SyncRunner::install() && plugin_assetsynccontroller_install_delete_cascades()
        && SyncTask::register() && BillingConfiguration::registerTask() && Access::install();
}

function plugin_assetsynccontroller_install_delete_cascades(): bool
{
    global $DB;

    $connections = PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE;
    $routes = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
    $relationships = [
        ['asc_route_connection', $routes, 'connections_id', $connections],
        ['asc_mapping_route', PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE, 'routes_id', $routes],
        ['asc_syncitem_route', PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, 'routes_id', $routes],
    ];

    foreach ($relationships as [$constraint, $child, $column, $parent])
    {
        $childTable = DBmysql::quoteName($child);
        $parentTable = DBmysql::quoteName($parent);
        $foreignColumn = DBmysql::quoteName($column);
        $constraintName = DBmysql::quoteName($constraint);
        $definition = $DB->doQuery("SHOW CREATE TABLE $childTable")->fetch_assoc()['Create Table'];
        if (str_contains($definition, "CONSTRAINT $constraintName FOREIGN KEY"))
        {
            continue;
        }

        if ($child === $routes)
        {
            // Older versions kept routes after deletion. Preserve that history with a NULL link.
            if ($DB->getField($routes, 'connections_id', false)['Null'] !== 'YES')
            {
                $type = $DB->getField($connections, 'id', false)['Type'];
                $DB->doQuery("ALTER TABLE $childTable MODIFY $foreignColumn $type DEFAULT NULL");
            }
            $DB->doQuery("UPDATE $childTable AS route
                LEFT JOIN $parentTable AS connection ON route.$foreignColumn = connection.id
                SET route.$foreignColumn = NULL
                WHERE connection.id IS NULL AND route.$foreignColumn IS NOT NULL");
        }

        // The database deletes the whole subtree atomically and rejects concurrent orphan inserts.
        $DB->doQuery("ALTER TABLE $childTable ADD CONSTRAINT $constraintName
            FOREIGN KEY ($foreignColumn) REFERENCES $parentTable (id) ON DELETE CASCADE");
    }

    return true;
}

function plugin_assetsynccontroller_uninstall(): bool
{
    global $DB;

    Access::uninstall();
    BillingConfiguration::uninstall();
    SyncFlags::uninstall();
    SyncRunner::uninstall();
    Config::deleteConfigurationValues(LocalAccount::CONFIG_CONTEXT, [LocalAccount::CONFIG_KEY]);
    // Remove mappings before their routes, then remove connections and encrypted credentials.
    $DB->dropTable(PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE, true);
    $DB->dropTable(PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE, true);
    return $DB->dropTable(PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE, true) === true;
}
