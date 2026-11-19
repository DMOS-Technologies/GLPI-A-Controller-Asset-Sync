<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use Glpi\Asset\CustomFieldDefinition;
use Glpi\Search\SearchOption;
use Infocom;

final class BillingFields
{
    public static function assetTypes(): array
    {
        global $CFG_GLPI;

        $assetTypes = [];
        $inventoryTypes = $CFG_GLPI['asset_types'] ?? [];
        $importTypes = $CFG_GLPI['ruleimportasset_types'] ?? [];
        foreach (array_unique(array_merge($inventoryTypes, $importTypes)) as $type)
        {
            if (!is_subclass_of($type, CommonDBTM::class) || !$type::canView())
            {
                continue;
            }
            // Preserve access to existing mappings for inventory and data center asset types.
            if (in_array($type, $inventoryTypes, true) || (new $type())->getModelClass() !== null)
            {
                $assetTypes[$type] = $type::getTypeName(1);
            }
        }
        asort($assetTypes, SORT_NATURAL | SORT_FLAG_CASE);
        return $assetTypes;
    }

    public static function forAssetType(string $itemtype, array $entityIds): array
    {
        $groups = ['dropdown' => [], 'date' => [], 'boolean' => [], 'number' => [], 'text' => []];
        if (!isset(self::assetTypes()[$itemtype]))
        {
            return $groups;
        }

        $options = SearchOption::getOptionsForItemtype($itemtype);
        foreach (MappingFields::fromSearchOptions($options) as $id => $descriptor)
        {
            $definition = $options[$id]['field_definition'] ?? null;
            if ($definition instanceof CustomFieldDefinition)
            {
                if (!empty($definition->getFieldType()->getOptionValues()['hidden']))
                {
                    continue;
                }
                $descriptor['source'] = 'GLPI custom asset fields';
            }

            try
            {
                $field = SyncFields::resolve($itemtype, ['id' => $id] + $descriptor);
                if (($field['container'] ?? null) !== null)
                {
                    // One mapping applies to every selected entity. Omit containers unavailable in any of them.
                    foreach ($entityIds as $entityId)
                    {
                        SyncFields::checkContainerEntity($field['container'], (int) $entityId);
                    }
                }
                $kind = $field['kind'] === 'integer' ? 'number' : $field['kind'];
            }
            catch (SyncException)
            {
                // Financial dates and amounts are native asset fields stored in a separate GLPI record.
                $kind = self::financialFieldKind($options[$id]);
            }

            if ($kind !== null && isset($groups[$kind]))
            {
                $groups[$kind][$id] = $descriptor + ['kind' => $kind];
            }
        }
        return $groups;
    }

    private static function financialFieldKind(array $option): ?string
    {
        global $DB;

        if (!empty($option['pfields_fields_id']) || !Infocom::canView())
        {
            return null;
        }
        $infocomTable = Infocom::getTable();
        if ($option['table'] !== $infocomTable)
        {
            // A direct dropdown on the financial record still has one value per asset.
            $beforeJoin = $option['joinparams']['beforejoin'] ?? [];
            if (is_array($beforeJoin) && count($beforeJoin) === 1 && ($beforeJoin[0]['table'] ?? null) === $infocomTable
                && ($option['datatype'] ?? null) === 'dropdown'
                && is_string($option['linkfield'] ?? null) && $DB->fieldExists($infocomTable, $option['linkfield']))
            {
                return 'dropdown';
            }
            return null;
        }
        if (!$DB->fieldExists($infocomTable, $option['field']))
        {
            return null;
        }
        return match ($option['datatype'] ?? '') {
            'date' => 'date',
            'number', 'decimal' => 'number',
            default => null,
        };
    }
}
