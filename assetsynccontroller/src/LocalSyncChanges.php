<?php

namespace GlpiPlugin\Assetsynccontroller;

use Glpi\DBAL\QueryExpression;
use Glpi\Search\SearchOption;
use Log;
use Notepad;

final class LocalSyncChanges
{
    private const PAGE_SIZE = 1000;
    private const NOTES_OPTION = 200;

    /**
     * Read mapped edits in the route's current asset scope.
     *
     * @return array{cursor: int, changes: array<int, list<int>>}
     */
    public static function scan(string $itemType, array $assetCriteria, array $optionIds, ?int $cursor): array
    {
        global $DB;

        $logs = Log::getTable();
        $latest = $DB->request([
            'SELECT' => [new QueryExpression('MAX(id) AS latest_id')],
            'FROM' => $logs,
        ])->current();
        $highWater = max($cursor ?? 0, (int) ($latest['latest_id'] ?? 0));

        // New assets are handled by inventory. A new cursor only tracks future edits.
        if ($cursor === null || $cursor === $highWater || $optionIds === [])
        {
            return ['cursor' => $highWater, 'changes' => []];
        }

        $assets = $itemType::getTable();
        $fields = self::mappedOptionsByAuditOption($itemType, $optionIds);
        $fieldCriteria = [ ["$logs.id_search_option" => array_keys($fields)] ];
        if (isset($fields[self::NOTES_OPTION]))
        {
            // GLPI's native note history points at the parent asset but uses option zero.
            $fieldCriteria[] = [
                "$logs.itemtype_link" => Notepad::class,
                "$logs.linked_action" => [Log::HISTORY_ADD_SUBITEM, Log::HISTORY_UPDATE_SUBITEM, Log::HISTORY_DELETE_SUBITEM],
            ];
        }

        $where = [
            "$logs.itemtype" => $itemType,
            'AND' => [
                ["$logs.id" => ['>', $cursor]],
                ["$logs.id" => ['<=', $highWater]],
                // Ignore inbound sync echoes. BillingHistory remains a local mapped edit.
                ['NOT' => ["$logs.itemtype_link" => SyncHistory::class]],
                ['OR' => $fieldCriteria],
            ],
        ];
        foreach ($assetCriteria as $field => $value)
        {
            $where["$assets.$field"] = $value;
        }

        $rows = $DB->request([
            'SELECT' => ["$logs.id", "$logs.items_id", "$logs.id_search_option", "$logs.itemtype_link"],
            'FROM' => $logs,
            'INNER JOIN' => [$assets => ['ON' => [$logs => 'items_id', $assets => 'id']]],
            'WHERE' => $where,
            'ORDER' => ["$logs.id"],
            'LIMIT' => self::PAGE_SIZE,
        ]);

        $changes = [];
        $lastId = $cursor;
        foreach ($rows as $row)
        {
            $lastId = (int) $row['id'];
            $id = $row['itemtype_link'] === Notepad::class ? self::NOTES_OPTION : (int) $row['id_search_option'];
            foreach ($fields[$id] ?? [] as $mappedOptionId)
            {
                $changes[(int) $row['items_id']][$mappedOptionId] = $mappedOptionId;
            }
        }
        foreach ($changes as &$changedFields)
        {
            $changedFields = array_values($changedFields);
        }
        unset($changedFields);

        // Do not skip matching history past a full page. When caught up, skip unrelated history.
        $newCursor = count($rows) === self::PAGE_SIZE ? $lastId : $highWater;
        return ['cursor' => $newCursor, 'changes' => $changes];
    }

    private static function mappedOptionsByAuditOption(string $itemType, array $optionIds): array
    {
        global $DB;

        $table = $itemType::getTable();
        $columns = $DB->listFields($table);
        $options = SearchOption::getOptionsForItemtype($itemType);
        $mappings = [];
        $mappedColumns = [];
        foreach ($optionIds as $optionId)
        {
            $optionId = (int) $optionId;
            $mappings[$optionId] = [$optionId];
            $column = self::nativeColumn($options[$optionId] ?? [], $table, $columns);
            if ($column !== null)
            {
                $mappedColumns[$column][] = $optionId;
            }
        }

        // Native GLPI history uses the first search option for a column. A saved mapping can use an alias.
        foreach ($options as $optionId => $option)
        {
            if (!is_int($optionId) || !is_array($option))
            {
                continue;
            }
            $column = self::nativeColumn($option, $table, $columns);
            if ($column !== null && isset($mappedColumns[$column]))
            {
                $mappings[$optionId] = $mappedColumns[$column];
            }
        }
        return $mappings;
    }

    private static function nativeColumn(array $option, string $table, array $columns): ?string
    {
        // Different native custom fields can share storage. Their history IDs must stay distinct.
        if (isset($option['pfields_fields_id']) || isset($option['field_definition']))
        {
            return null;
        }

        $column = ($option['table'] ?? null) === $table ? ($option['field'] ?? null) : ($option['linkfield'] ?? null);
        return is_string($column) && !in_array($column, ['id', 'custom_fields'], true) && isset($columns[$column])
            ? $column
            : null;
    }
}
