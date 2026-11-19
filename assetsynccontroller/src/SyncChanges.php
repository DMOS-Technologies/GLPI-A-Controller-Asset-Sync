<?php

namespace GlpiPlugin\Assetsynccontroller;

use DBConnection;
use Throwable;

final class SyncChanges
{
    public static function install(): void
    {
        global $DB;

        $table = PLUGIN_ASSETSYNCCONTROLLER_CURSORS_TABLE;
        $routes = PLUGIN_ASSETSYNCCONTROLLER_ROUTES_TABLE;
        $idType = $DB->getField($routes, 'id', false)['Type'];
        $charset = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $DB->doQuery("
            CREATE TABLE IF NOT EXISTS $table (
                routes_id $idType NOT NULL,
                asset_type varchar(255) NOT NULL,
                cursor_a bigint unsigned DEFAULT NULL,
                cursor_b bigint unsigned DEFAULT NULL,
                PRIMARY KEY (routes_id, asset_type),
                CONSTRAINT asc_cursor_route FOREIGN KEY (routes_id) REFERENCES $routes (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
        ");
    }

    public static function collect(array $target, ?AgentApi $agent): void
    {
        global $DB;

        $route = $target['route'];
        $scope = ['routes_id' => $route['id'], 'asset_type' => $target['type']];
        $table = PLUGIN_ASSETSYNCCONTROLLER_CURSORS_TABLE;
        $saved = $DB->request(['FROM' => $table, 'WHERE' => $scope])->current();
        $cursorA = isset($saved['cursor_a']) ? (int) $saved['cursor_a'] : null;
        $cursorB = isset($saved['cursor_b']) ? (int) $saved['cursor_b'] : null;
        $optionIds = array_map('intval', array_column($target['mappings'], 'controller_option_id'));
        $local = LocalSyncChanges::scan($target['type'], $target['criteria'], array_unique($optionIds), $cursorA);

        $fieldsB = [];
        foreach ($target['mappings'] as $mapping)
        {
            $id = (int) $mapping['agent_option_id'];
            $fieldsB[$id] = ['id' => $id] + json_decode($mapping['agent_field'], true, flags: JSON_THROW_ON_ERROR);
        }

        // Page through history over successive scheduled runs. Each page is durable before its cursor advances.
        $remote = $agent?->syncAsset([
            'operation' => 'changes',
            'itemtype' => $target['type'],
            'entity_id' => (int) $route['agent_entity_id'],
            'cursor' => $cursorB,
            'fields' => array_values($fieldsB),
        ]);
        $remoteChanges = self::remoteChanges($remote, $cursorB, $fieldsB);

        $DB->beginTransaction();
        try
        {
            foreach ($local['changes'] as $assetId => $ids)
            {
                self::queue($scope + ['assets_id' => $assetId], 'a', $ids);
            }
            if ($remoteChanges !== null)
            {
                foreach ($remoteChanges as $change)
                {
                    self::queue($scope + ['serial' => $change['serial']], 'b', $change['field_ids']);
                }
                $cursorB = $remote['cursor'];
            }

            $cursors = ['cursor_a' => $local['cursor'], 'cursor_b' => $cursorB];
            if (!$saved)
            {
                $DB->insert($table, $scope + $cursors);
            }
            elseif ($local['cursor'] !== $cursorA || $cursorB !== (isset($saved['cursor_b']) ? (int) $saved['cursor_b'] : null))
            {
                $DB->update($table, $cursors, $scope);
            }
            $DB->commit();
        }
        catch (Throwable $error)
        {
            $DB->rollBack();
            throw $error;
        }
    }

    private static function remoteChanges(?array $response, ?int $cursor, array $fields): ?array
    {
        // A failed or malformed response leaves B's cursor in place, so the next run can retry it.
        if (($response['ok'] ?? false) !== true || !is_int($response['cursor'] ?? null)
            || $response['cursor'] < ($cursor ?? 0) || !is_array($response['changes'] ?? null)
            || !array_is_list($response['changes']))
        {
            return null;
        }

        foreach ($response['changes'] as $change)
        {
            if (!is_string($change['serial'] ?? null) || trim($change['serial']) === ''
                || !is_array($change['field_ids'] ?? null) || !array_is_list($change['field_ids']))
            {
                return null;
            }
            foreach ($change['field_ids'] as $id)
            {
                if (!is_int($id) || !isset($fields[$id]))
                {
                    return null;
                }
            }
        }
        return $response['changes'];
    }

    private static function queue(array $where, string $side, array $ids): void
    {
        global $DB;

        if ($ids === [])
        {
            return;
        }
        $table = PLUGIN_ASSETSYNCCONTROLLER_SYNC_TABLE;
        foreach ($DB->request([
            'SELECT' => ['id', 'serial', 'status', 'pending_fields'],
            'FROM' => $table,
            'WHERE' => $where,
        ]) as $entry)
        {
            // Pending assets already have a full first sync. Cancelled assets need an explicit queue request.
            if ($entry['serial'] === '' || ($entry['status'] !== 'finished' && $entry['pending_fields'] === null))
            {
                continue;
            }
            $fields = json_decode($entry['pending_fields'] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
            $fields[$side] = array_values(array_unique(array_merge($fields[$side] ?? [], $ids)));
            $changes = ['pending_fields' => json_encode($fields, JSON_THROW_ON_ERROR)];
            if ($entry['status'] === 'finished')
            {
                $changes['status'] = 'queued';
            }
            // A newer manual request already includes every mapping for this asset.
            $DB->update($table, $changes, [
                'id' => $entry['id'], 'status' => $entry['status'], 'pending_fields' => $entry['pending_fields'],
            ]);
        }
    }

    public static function selectMappings(array $mappings, ?string $pendingFields): array
    {
        if ($pendingFields === null)
        {
            return $mappings;
        }
        $changed = json_decode($pendingFields, true, flags: JSON_THROW_ON_ERROR);
        return array_values(array_filter($mappings, static fn(array $mapping): bool =>
            in_array((int) $mapping['controller_option_id'], $changed['a'] ?? [], true)
            || in_array((int) $mapping['agent_option_id'], $changed['b'] ?? [], true)));
    }
}
