<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use Entity;
use Glpi\DBAL\QuerySubQuery;
use Glpi\Search\SearchOption;
use Infocom;
use PluginFieldsProfile;
use Profile_User;
use Session;
use User;

final class BillingAssetFields
{
    public static function validate(string $itemtype, array $descriptor, int $entity, array $kinds, bool $write = false): array
    {
        $id = $descriptor['id'] ?? null;
        if (!is_int($id) || $id < 1)
        {
            throw new SyncException('field_unavailable', 422);
        }

        // Financial fields are in the asset's native InfoCom record rather than its main table.
        $option = SearchOption::getOptionsForItemtype($itemtype)[$id] ?? [];
        $field = self::financialField($id, $option, $descriptor);
        if ($field === null)
        {
            $field = SyncFields::resolve($itemtype, $descriptor);
            $field['financial'] = false;
        }

        $kind = $field['kind'];
        if (!in_array($kind, $kinds, true) && !($kind === 'integer' && in_array('number', $kinds, true)))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        self::checkAccess($field, $entity, $write);
        return $field;
    }

    public static function read(CommonDBTM $asset, array $field): mixed
    {
        $entity = (int) $asset->fields['entities_id'];
        self::checkAccess($field, $entity, false);
        $value = self::storedValue($asset, $field);
        if ($field['kind'] !== 'dropdown')
        {
            // The billing calculator distinguishes an invalid legacy date from an unavailable field.
            return $value;
        }
        if ((int) $value < 1)
        {
            return null;
        }

        // Only look up this asset's stored option, even when the native list has many entries.
        $options = self::dropdownLabels($field, $entity, (int) $value);
        if (!isset($options[(int) $value]))
        {
            throw new SyncException('reference_unavailable', 422);
        }
        return $options[(int) $value];
    }

    public static function dropdownOptions(array $field, int $entity): array
    {
        self::checkAccess($field, $entity, false);
        return self::dropdownLabels($field, $entity);
    }

    private static function dropdownLabels(array $field, int $entity, ?int $id = null): array
    {
        global $DB;

        $table = $field['reference_table'] ?? null;
        if ($field['kind'] !== 'dropdown' || $table === null)
        {
            throw new SyncException('invalid_reference_value', 422);
        }

        $labelColumn = $DB->fieldExists($table, 'name') ? 'name' : 'completename';
        $where = [];
        if ($table === Entity::getTable())
        {
            $where['id'] = $entity;
        }
        elseif ($table === User::getTable())
        {
            $profiles = Profile_User::getTable();
            $where['id'] = new QuerySubQuery([
                'SELECT' => 'users_id',
                'FROM' => $profiles,
                'WHERE' => getEntitiesRestrictCriteria($profiles, '', $entity, true),
            ]);
        }
        elseif ($DB->fieldExists($table, 'entities_id'))
        {
            // Follow GLPI inheritance rules for shared ancestor options.
            $where = getEntitiesRestrictCriteria($table, '', $entity, $DB->fieldExists($table, 'is_recursive'));
        }
        foreach (['is_deleted' => 0, 'is_active' => 1] as $column => $value)
        {
            if ($DB->fieldExists($table, $column))
            {
                $where[$column] = $value;
            }
        }
        if ($id !== null)
        {
            $where = $where === [] ? ['id' => $id] : [['id' => $id], $where];
        }

        $options = [];
        foreach ($DB->request(['SELECT' => ['id', $labelColumn], 'FROM' => $table, 'WHERE' => $where]) as $row)
        {
            $label = trim((string) $row[$labelColumn]);
            if ($label !== '')
            {
                $options[(int) $row['id']] = $label;
            }
        }
        return $options;
    }

    public static function dropdownId(array $field, string $label, int $entity): int
    {
        // Database text comparisons can be case insensitive. Billing labels are exact.
        $ids = array_keys(self::dropdownOptions($field, $entity), trim($label), true);
        if (count($ids) !== 1)
        {
            throw new SyncException('reference_unavailable', 422);
        }
        return $ids[0];
    }

    public static function write(CommonDBTM $asset, array $changes): bool
    {
        $entity = (int) $asset->fields['entities_id'];
        $native = [];
        $financial = [];
        $custom = [];
        $expected = [];
        foreach ($changes as ['field' => $field, 'value' => $value])
        {
            self::checkAccess($field, $entity, true);
            $kind = $field['kind'];
            $value = $kind === 'dropdown' ? ($value === null ? 0 : self::dropdownId($field, $value, $entity))
                : SyncValue::forType($value, $kind);
            $before = self::storedValue($asset, $field);
            if (self::comparable($before, $kind) === self::comparable($value, $kind))
            {
                continue;
            }

            $column = $field['column'];
            if ($field['container'] !== null)
            {
                $custom[$field['container']->getID()][$column] = $value;
            }
            elseif ($field['financial'])
            {
                $financial[$column] = $value;
            }
            else
            {
                $native[$column] = $field['native_custom'] && $value === null ? '' : $value;
            }
            $expected[] = [$field, $value, $before];
        }
        if ($expected === [])
        {
            return false;
        }

        // The caller holds a transaction so an error rolls back every field of this calculation.
        $id = $asset->getID();
        if ($native !== [] && !$asset->update(['id' => $id, '_skip_rules' => true] + $native))
        {
            throw new SyncException('asset_update_failed', 422);
        }
        if ($financial !== [])
        {
            self::writeFinancial($asset, $financial);
        }
        if ($custom !== [])
        {
            SyncFields::writeCustom($asset, $custom);
        }
        if (!$asset->getFromDB($id))
        {
            throw new SyncException('asset_update_failed', 422);
        }

        foreach ($expected as [$field, $value, $before])
        {
            $stored = self::storedValue($asset, $field);
            $kind = $field['kind'];
            if (self::comparable($stored, $kind) !== self::comparable($value, $kind))
            {
                throw new SyncException('asset_update_failed', 422);
            }
            if ($field['container'] !== null && $asset->dohistory)
            {
                // Billing is a local edit. Give it its own history marker so sync can pick it up.
                if ($kind === 'dropdown')
                {
                    $labels = self::dropdownOptions($field, $entity);
                    $before = $labels[(int) $before] ?? null;
                    $stored = $labels[(int) $stored] ?? null;
                }
                SyncHistory::record($asset, $field['id'], ['kind' => $kind, 'value' => $before],
                    ['kind' => $kind, 'value' => $stored], BillingHistory::class);
            }
        }
        if ($custom !== [] && array_key_exists('date_mod', $asset->fields)
            && $asset->fields['date_mod'] !== $_SESSION['glpi_currenttime'])
        {
            // Saving a Fields record does not automatically update the parent asset's timestamp.
            $asset->fields['date_mod'] = $_SESSION['glpi_currenttime'];
            if (!$asset->updateInDB(['date_mod']))
            {
                throw new SyncException('asset_update_failed', 422);
            }
        }
        return true;
    }

    private static function checkAccess(array $field, int $entity, bool $write): void
    {
        if (($write && !$field['can_write'])
            || ($field['financial'] && (!Infocom::canView() || ($write && !Infocom::canUpdate()))))
        {
            throw new SyncException('field_read_only', 403);
        }
        if ($field['container'] === null)
        {
            return;
        }
        SyncFields::checkContainerEntity($field['container'], $entity);
        if (!Session::isCron())
        {
            $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            $right = PluginFieldsProfile::getRightOnContainer($profileId, $field['container']->getID());
            if ($right < READ || ($write && $right <= READ))
            {
                throw new SyncException('field_read_only', 403);
            }
        }
    }

    private static function storedValue(CommonDBTM $asset, array $field): mixed
    {
        global $DB;

        $column = $field['column'];
        if (!$field['financial'] && $field['container'] === null)
        {
            return $asset->fields[$column] ?? null;
        }

        $where = ['items_id' => $asset->getID(), 'itemtype' => $asset::class];
        if ($field['container'] !== null)
        {
            $where['plugin_fields_containers_id'] = $field['container']->getID();
        }
        $row = $DB->request(['SELECT' => [$column], 'FROM' => $field['table'], 'WHERE' => $where])->current();
        return $row[$column] ?? null;
    }

    private static function comparable(mixed $value, string $kind): mixed
    {
        if ($kind === 'dropdown')
        {
            return (int) $value;
        }
        if ($kind === 'date' && is_string($value) && str_starts_with($value, '0000-00-00'))
        {
            return null;
        }
        try
        {
            return SyncValue::forType($value, $kind);
        }
        catch (SyncException)
        {
            // An old invalid value can still be replaced by a valid result.
            return $value;
        }
    }

    private static function writeFinancial(CommonDBTM $asset, array $values): void
    {
        $finance = new Infocom();
        if ($finance->getFromDBforDevice($asset::class, $asset->getID()))
        {
            $saved = $finance->canUpdate() && $finance->update(['id' => $finance->getID()] + $values);
        }
        else
        {
            $saved = $finance->canCreate() && $finance->add([
                'items_id' => $asset->getID(),
                'itemtype' => $asset::class,
            ] + $values);
        }
        if (!$saved)
        {
            throw new SyncException('asset_update_failed', 422);
        }
    }

    private static function financialField(int $id, array $option, array $descriptor): ?array
    {
        global $DB;

        $table = Infocom::getTable();
        $join = $option['joinparams']['beforejoin'] ?? [];
        $financeDropdown = is_array($join) && count($join) === 1 && ($join[0]['table'] ?? null) === $table
            && ($option['datatype'] ?? null) === 'dropdown';
        if (!Infocom::canView() || ($option === []) || !empty($option['nodisplay'])
            || ($option['table'] !== $table && !$financeDropdown)
            || $option['table'] !== ($descriptor['table'] ?? null)
            || $option['field'] !== ($descriptor['field'] ?? null)
            || ($option['linkfield'] ?? null) !== ($descriptor['linkfield'] ?? null))
        {
            return null;
        }

        $column = $financeDropdown ? $option['linkfield'] : $option['field'];
        if (!is_string($column) || !$DB->fieldExists($table, $column))
        {
            return null;
        }
        $kind = match ($option['datatype'] ?? '') {
            'date' => 'date',
            'number', 'decimal' => 'number',
            'string', 'text' => 'text',
            default => $financeDropdown ? 'dropdown' : null,
        };
        if ($kind === null)
        {
            return null;
        }

        return [
            'id' => $id,
            'column' => $column,
            'table' => $table,
            'kind' => $kind,
            'financial' => true,
            'container' => null,
            'can_write' => Infocom::canUpdate(),
            'reference_table' => $financeDropdown ? $option['table'] : null,
            'native_custom' => false,
        ];
    }
}
