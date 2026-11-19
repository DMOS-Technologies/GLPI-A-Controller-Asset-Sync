<?php

namespace GlpiPlugin\Assetsynccontroller;

class MappingFields
{
    public static function fromSearchOptions(array $options): array
    {
        $fields = [];
        $group = '';
        $groupName = '';
        foreach ($options as $id => $option)
        {
            // Keep the GLPI search group before sorting fields. It may differ from the actual asset tab.
            if (is_string($id))
            {
                $group = $id;
                $groupName = is_string($option) ? $option : ($option['name'] ?? '');
                continue;
            }

            // Field IDs stay distinct even when names or UIDs repeat.
            if ($id < 1 || !is_array($option) || !empty($option['nodisplay']))
            {
                continue;
            }

            $name = $option['name'] ?? null;
            $field = $option['field'] ?? null;
            $table = $option['table'] ?? null;
            if (!is_string($name) || $name === '' || !is_string($field) || $field === '' || !is_string($table) || $table === '')
            {
                continue;
            }

            // Keep metadata for future sync rules. A search option ID by itself can change across installations.
            $fields[$id] = [
                'name' => $name,
                'field' => $field,
                'table' => $table,
                'source' => self::sourceLabel($option, $group, is_string($groupName) ? $groupName : ''),
            ];
            foreach (['uid', 'linkfield', 'datatype'] as $key)
            {
                if (isset($option[$key]) && is_string($option[$key]))
                {
                    $fields[$id][$key] = $option[$key];
                }
            }
        }

        uasort($fields, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        return $fields;
    }

    public static function bySource(array $fields): array
    {
        $groups = [];
        foreach ($fields as $id => $field)
        {
            $groups[$field['source']][$id] = $field;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        return $groups;
    }

    public static function sourceLabel(array $option, string $group = '', string $groupName = ''): string
    {
        if (is_string($option['source'] ?? null))
        {
            return $option['source'];
        }

        // Older saved pairs have a table and sometimes a UID, but no saved source label.
        $table = $option['table'] ?? '';
        $joinedTable = $option['joinparams']['beforejoin']['table'] ?? '';
        $uid = $option['uid'] ?? '';
        if (!empty($option['pfields_fields_id'])
            || (is_string($table) && str_starts_with($table, 'glpi_plugin_fields_'))
            || (is_string($joinedTable) && str_starts_with($joinedTable, 'glpi_plugin_fields_'))
            || (is_string($uid) && preg_match('/(?:^|\.)PluginFields[A-Z0-9_][A-Za-z0-9_]*\./', $uid)))
        {
            return 'Fields plugin';
        }

        if ((is_string($table) && str_starts_with($table, 'glpi_plugin_')) || $group === 'plugins')
        {
            return 'GLPI plugins';
        }

        return $groupName === '' ? 'Native GLPI' : 'Native GLPI · ' . $groupName;
    }
}
