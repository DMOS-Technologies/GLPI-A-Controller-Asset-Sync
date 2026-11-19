<?php

namespace GlpiPlugin\Assetsynccontroller;

use DBmysql;
use Throwable;

final class RouteSettings
{
    public static function withLock(int $routeId, callable $operation, int $timeout = 0): mixed
    {
        global $DB;

        // Serialize a route edit with Agent B writes without opening a database transaction across HTTP.
        $lock = "CONCAT('asc-route:', LEFT(SHA2(DATABASE(), 256), 32), ':', $routeId)";
        $result = $DB->doQuery("SELECT GET_LOCK($lock, $timeout) AS acquired")->fetch_assoc();
        if ((int) ($result['acquired'] ?? 0) !== 1)
        {
            throw new SyncProblem('This route is busy. Try again after its current sync finishes.');
        }
        try
        {
            return $operation();
        }
        finally
        {
            $DB->doQuery("SELECT RELEASE_LOCK($lock)");
        }
    }

    public static function update(array $originalRoute, array $values): bool
    {
        return self::change($originalRoute, static function (array $current) use ($values): bool
        {
            global $DB;

            $connection = $DB->request([
                'SELECT' => ['id'],
                'FROM' => PLUGIN_ASSETSYNCCONTROLLER_CONNECTIONS_TABLE,
                'WHERE' => ['id' => $values['connections_id'], 'settings_revision' => $values['connection_revision']],
            ])->current();
            if (!$connection)
            {
                return false;
            }

            $scope = ['routes_id' => $current['id']];
            $oldTypes = array_keys(json_decode($current['asset_types'], true, flags: JSON_THROW_ON_ERROR));
            $newTypes = array_keys(json_decode($values['asset_types'], true, flags: JSON_THROW_ON_ERROR));
            $removed = array_values(array_diff($oldTypes, $newTypes));
            $restart = false;
            foreach (['connections_id', 'connection_revision', 'controller_entity_id', 'agent_entity_id'] as $key)
            {
                $restart = $restart || (int) $current[$key] !== (int) $values[$key];
            }

            // An edited endpoint/entity needs a new sync baseline. Unchanged types keep their field configuration.
            foreach ([PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE, PLUGIN_ASSETSYNCCONTROLLER_CURSORS_TABLE] as $table)
            {
                if ($restart || $removed !== [])
                {
                    $DB->delete($table, $restart ? $scope : $scope + ['asset_type' => $removed]);
                }
            }
            $connectionChanged = (int) $current['connections_id'] !== (int) $values['connections_id'];
            if ($connectionChanged || $removed !== [])
            {
                // Field IDs from another connection can refer to different fields even on the same asset type.
                $DB->delete(PLUGIN_ASSETSYNCCONTROLLER_MAPPINGS_TABLE, $connectionChanged ? $scope : $scope + ['asset_type' => $removed]);
                foreach ($connectionChanged ? $oldTypes : $removed as $type)
                {
                    SyncFlags::save((int) $current['id'], $type, []);
                }
            }
            return $DB->update(PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE, $values, ['id' => $current['id']]);
        });
    }

    public static function delete(array $originalRoute): bool
    {
        // Foreign keys delete the field mappings, flags, queue, and change cursors with the route.
        return self::change($originalRoute, static function (array $current): bool
        {
            global $DB;
            return $DB->delete(PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE, ['id' => $current['id']]);
        });
    }

    private static function change(array $original, callable $operation): bool
    {
        $routeId = (int) $original['id'];
        return self::withLock($routeId, static function () use ($routeId, $original, $operation): bool
        {
            global $DB;

            $table = DBmysql::quoteName(PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE);
            $DB->beginTransaction();
            try
            {
                $current = $DB->doQuery("SELECT * FROM $table WHERE id = $routeId FOR UPDATE")->fetch_assoc();
                if (!$current || array_intersect_key($original, $current) != $current || !$operation($current))
                {
                    $DB->rollBack();
                    return false;
                }
                $DB->commit();
                return true;
            }
            catch (Throwable $error)
            {
                $DB->rollBack();
                throw $error;
            }
        }, 8);
    }
}
