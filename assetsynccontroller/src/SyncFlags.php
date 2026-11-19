<?php

namespace GlpiPlugin\Assetsynccontroller;

use DBConnection;

final class SyncFlags
{
    private const TABLE = 'glpi_plugin_assetsynccontroller_syncflags';

    public static function install(): void
    {
        global $DB;

        $table = self::TABLE;
        $routes = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
        $routeIdType = $DB->getField($routes, 'id', false)['Type'];
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $DB->doQuery("
            CREATE TABLE IF NOT EXISTS $table (
                routes_id $routeIdType NOT NULL,
                asset_type varchar(255) NOT NULL,
                flags text NOT NULL,
                PRIMARY KEY (routes_id, asset_type),
                CONSTRAINT asc_flag_route FOREIGN KEY (routes_id) REFERENCES $routes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
        ");
    }

    public static function uninstall(): void
    {
        global $DB;

        $DB->dropTable(self::TABLE, true);
    }

    public static function get(int $routeId, string $assetType): array
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['flags'],
            'FROM' => self::TABLE,
            'WHERE' => ['routes_id' => $routeId, 'asset_type' => $assetType],
        ])->current();
        return $row ? json_decode($row['flags'], true, flags: JSON_THROW_ON_ERROR) : [];
    }

    public static function save(int $routeId, string $assetType, array $flags): void
    {
        global $DB;

        $scope = ['routes_id' => $routeId, 'asset_type' => $assetType];
        if ($flags === [])
        {
            $DB->delete(self::TABLE, $scope);
            return;
        }

        // Replace the two selections together, without queuing any assets.
        $insert = $DB->buildInsert(self::TABLE, $scope + ['flags' => json_encode($flags, JSON_THROW_ON_ERROR)]);
        $DB->doQuery($insert . ' ON DUPLICATE KEY UPDATE flags = VALUES(flags)');
    }

    public static function sameField(array $left, array $right): bool
    {
        if (isset($left['id'], $right['id']) && (int) $left['id'] === (int) $right['id'])
        {
            return true;
        }

        // GLPI custom asset fields can share a generic storage descriptor across distinct options.
        if ($left['table'] === 'glpi_assets_assets' && $left['field'] === 'value')
        {
            return false;
        }

        // GLPI may publish more than one search option for the same destination field.
        return $left['table'] === $right['table'] && $left['field'] === $right['field']
            && ($left['linkfield'] ?? $left['field']) === ($right['linkfield'] ?? $right['field']);
    }
}
