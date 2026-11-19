<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use DBmysql;
use Entity;
use Glpi\Asset\Asset;
use Glpi\Asset\CustomFieldDefinition;
use Glpi\Asset\CustomFieldType as AssetFieldType;
use Glpi\DBAL\QueryExpression;
use Glpi\Search\SearchOption;
use Log;
use Plugin;
use PluginFieldsContainer;
use PluginFieldsField;
use PluginFieldsProfile;
use PluginFieldsStatusOverride;
use Session;

final class SyncFields
{
    public static function populatedNativeReferences(CommonDBTM $asset, array $mappedWrites): array
    {
        $entries = [];
        foreach (SearchOption::getOptionsForItemtype($asset::class) as $id => $option)
        {
            $column = $option['linkfield'] ?? '';
            if (!is_int($id) || !is_string($column) || isset($entries[$column]) || isset($mappedWrites[$id])
                || $column === 'entities_id' || empty($asset->fields[$column]) || !empty($option['pfields_fields_id'])
                || !in_array($option['field'] ?? null, ['name', 'completename'], true)
                || ($option['table'] ?? null) === $asset::getTable())
            {
                continue;
            }

            $descriptor = [
                'id' => $id, 'table' => $option['table'], 'field' => $option['field'], 'linkfield' => $column,
                'name' => $option['name'] ?? $column,
            ];
            try
            {
                $field = self::resolve($asset::class, $descriptor);
                $value = self::readValue($field, $asset->fields[$column], (int) $asset->fields['entities_id']);
            }
            catch (SyncException)
            {
                // Unmapped references without a portable value need an explicit mapping.
                continue;
            }
            if ($field['can_write'] && $field['reference_table'] !== null && $value !== null)
            {
                $entries[$column] = ['field' => $descriptor, 'value' => $value];
            }
        }
        return array_values($entries);
    }

    public static function nativeColumnCanWrite(string $column): bool
    {
        // These belong to the destination's identity, lifecycle, or security settings.
        return preg_match('/\A[a-z][a-z0-9_]*\z/', $column) === 1
            && !preg_match('/(?:^custom_|_ids?$|password|token|secret)/i', $column)
            && !in_array($column, [
                'id', 'serial', 'uuid', 'checksum', 'last_inventory_update', 'date_creation', 'date_mod',
                'is_deleted', 'is_template', 'is_dynamic', 'is_recursive', 'is_locked', 'template_name',
                'completename', 'level', 'ancestors_cache', 'sons_cache', 'custom_fields', 'customfields',
                'custom_field_values', 'pictures', 'ticket_tco',
            ], true);
    }

    public static function resolve(string $itemtype, array $descriptor): array
    {
        global $DB;

        $id = $descriptor['id'] ?? null;
        $options = SearchOption::getOptionsForItemtype($itemtype);
        $option = is_int($id) ? ($options[$id] ?? null) : null;
        if ($option === null && is_int($id) && Session::isCron() && Plugin::isPluginActive('fields'))
        {
            // CLI cron has no browser profile. Fields supplies its own cron-aware search options.
            $options = PluginFieldsContainer::getAddSearchOptions($itemtype);
            $option = $options[$id] ?? null;
        }
        if (!is_array($option) || !empty($option['nodisplay'])
            || ($descriptor['table'] ?? null) !== ($option['table'] ?? null)
            || ($descriptor['field'] ?? null) !== ($option['field'] ?? null)
            || (isset($descriptor['linkfield']) && $descriptor['linkfield'] !== ($option['linkfield'] ?? null)))
        {
            throw new SyncException('field_unavailable', 422);
        }
        if (SyncNotes::isField($descriptor))
        {
            return ['id' => $id, 'kind' => 'notes', 'can_write' => true];
        }

        $table = $itemtype::getTable();
        $column = $option['field'];
        $container = null;
        $referenceTable = null;
        $multiple = false;
        $canWrite = false;
        $kind = null;
        $itemReference = null;
        $nativeCustom = false;
        $pluginFieldId = $option['pfields_fields_id'] ?? null;
        if ($pluginFieldId === null && str_starts_with($column, 'items_id_')
            && str_starts_with($option['table'], 'glpi_plugin_fields_'))
        {
            // Fields publishes the paired item ID immediately after the itemtype option.
            $pluginFieldId = $options[$id - 1]['pfields_fields_id'] ?? null;
        }

        if (($option['field_definition'] ?? null) instanceof CustomFieldDefinition)
        {
            // Native custom assets keep custom values through the GLPI model's custom_* inputs.
            $definition = $option['field_definition'];
            $nativeCustom = true;
            $column = 'custom_' . $definition->fields['system_name'];
            $kind = match ($definition->fields['type']) {
                AssetFieldType\BooleanType::class => 'boolean',
                AssetFieldType\NumberType::class => 'number',
                AssetFieldType\DateTimeType::class => 'datetime',
                AssetFieldType\DateType::class => 'date',
                default => 'text',
            };
            $fieldOptions = $definition->getFieldType()->getOptionValues();
            $canWrite = empty($fieldOptions['hidden']) && empty($fieldOptions['readonly']);
            if ($definition->fields['type'] === AssetFieldType\DropdownType::class)
            {
                $referenceType = $definition->fields['itemtype'] ?? '';
                if (!is_subclass_of($referenceType, CommonDBTM::class))
                {
                    throw new SyncException('field_unavailable', 422);
                }
                $referenceTable = $referenceType::getTable();
                $multiple = !empty($fieldOptions['multiple']);
            }
        }
        elseif ($pluginFieldId !== null)
        {
            // The active profile's scoped Fields grant is already reflected in its search options.
            $field = new PluginFieldsField();
            $container = new PluginFieldsContainer();
            if (!$field->getFromDB((int) $pluginFieldId)
                || !$container->getFromDB((int) $field->fields['plugin_fields_containers_id'])
                || empty($field->fields['is_active']) || empty($container->fields['is_active'])
                || $field->fields['type'] === 'header')
            {
                throw new SyncException('field_unavailable', 422);
            }

            $containerClass = PluginFieldsContainer::getClassname($itemtype, $container->fields['name']);
            $table = $containerClass::getTable();
            $type = $field->fields['type'];
            $kind = match ($type) {
                'yesno' => 'boolean',
                'number' => 'number',
                'date', 'datetime' => $type,
                default => 'text',
            };
            $column = $field->fields['name'];
            $multiple = !empty($field->fields['multiple']);
            $canWrite = empty($field->fields['is_readonly']);
            if ($type === 'glpi_item')
            {
                $typeColumn = 'itemtype_' . $field->fields['name'];
                $idColumn = 'items_id_' . $field->fields['name'];
                if ($option['table'] !== $table || !in_array($option['field'], [$typeColumn, $idColumn], true))
                {
                    throw new SyncException('field_unavailable', 422);
                }
                $column = $option['field'];
                if ($column === $idColumn)
                {
                    $kind = 'item';
                    $itemReference = [
                        'type_column' => $typeColumn,
                        'types' => json_decode($field->fields['allowed_values'] ?? '', true) ?? [],
                    ];
                }
                else
                {
                    // The type by itself can be read but changing it would invalidate the linked ID.
                    $canWrite = false;
                }
            }
            elseif ($type === 'dropdown')
            {
                $column = 'plugin_fields_' . $column . 'dropdowns_id';
                $referenceTable = 'glpi_plugin_fields_' . $field->fields['name'] . 'dropdowns';
            }
            elseif (str_starts_with($type, 'dropdown-'))
            {
                $referenceClass = substr($type, strlen('dropdown-'));
                if (!is_subclass_of($referenceClass, CommonDBTM::class))
                {
                    throw new SyncException('field_unavailable', 422);
                }
                $referenceTable = $referenceClass::getTable();
            }
        }
        elseif ($option['table'] === $table && self::nativeColumnCanWrite($column))
        {
            $canWrite = true;
        }
        elseif ($option['table'] === $table && $column === 'serial')
        {
            // The serial number can be read or compared, but never changed by a field mapping.
        }
        else
        {
            $column = $option['linkfield'] ?? '';
            $referenceTable = $option['table'];
            $referenceClass = getItemTypeForTable($referenceTable);
            if (!in_array($option['field'], ['name', 'completename'], true)
                || !is_string($referenceClass) || !is_subclass_of($referenceClass, CommonDBTM::class)
                || getForeignKeyFieldForTable($referenceTable) !== $column)
            {
                throw new SyncException('field_unavailable', 422);
            }
            $canWrite = !in_array($column, ['entities_id', 'agents_id', 'snmpcredentials_id'], true);
        }

        if ((!$nativeCustom && !$DB->fieldExists($table, $column))
            || ($referenceTable !== null && !$DB->tableExists($referenceTable)))
        {
            throw new SyncException('field_unavailable', 422);
        }

        if ($referenceTable !== null)
        {
            if (!$DB->fieldExists($referenceTable, 'name') && !$DB->fieldExists($referenceTable, 'completename'))
            {
                throw new SyncException('field_unavailable', 422);
            }
            $kind = $multiple ? 'dropdown_list' : 'dropdown';
        }
        elseif ($kind === null)
        {
            $kind = self::nativeKind($option, $DB->getField($table, $column)['Type'] ?? '');
        }

        return [
            'id' => $id,
            'column' => $column,
            'table' => $table,
            'container' => $container,
            'reference_table' => $referenceTable,
            'multiple' => $multiple,
            'can_write' => $canWrite,
            'kind' => $kind,
            'item_reference' => $itemReference,
            'native_custom' => $nativeCustom,
        ];
    }

    private static function nativeKind(array $option, string $storageType): string
    {
        if (($option['datatype'] ?? '') === 'bool')
        {
            return 'boolean';
        }
        if (preg_match('/^(?:datetime|timestamp)\b/i', $storageType))
        {
            return 'datetime';
        }
        if (preg_match('/^date\b/i', $storageType))
        {
            return 'date';
        }
        if (preg_match('/^(?:tiny|small|medium|big)?int\b/i', $storageType))
        {
            return 'integer';
        }
        if (preg_match('/^(?:decimal|float|double)\b/i', $storageType))
        {
            return 'number';
        }
        return 'text';
    }

    public static function read(CommonDBTM $asset, array $descriptors): array
    {
        global $DB;

        $result = [];
        foreach ($descriptors as $descriptor)
        {
            if (!is_array($descriptor))
            {
                throw new SyncException('invalid_field', 400);
            }
            $field = self::resolve($asset::class, $descriptor);
            $id = $field['id'];
            if ($field['kind'] === 'notes')
            {
                $result[$id] = SyncNotes::read($asset);
                continue;
            }
            $rawValue = $asset->fields[$field['column']] ?? null;
            if ($field['container'] !== null)
            {
                self::checkContainerEntity($field['container'], (int) $asset->fields['entities_id']);
                $columns = [$field['column']];
                if ($field['item_reference'] !== null)
                {
                    $columns[] = $field['item_reference']['type_column'];
                }
                $row = $DB->request([
                    'SELECT' => $columns,
                    'FROM' => $field['table'],
                    'WHERE' => ['items_id' => $asset->getID(), 'itemtype' => $asset::class],
                ])->current();
                $rawValue = $row[$field['column']] ?? null;
                if ($field['item_reference'] !== null)
                {
                    $rawValue = self::itemValue(
                        $field['item_reference']['types'],
                        $row[$field['item_reference']['type_column']] ?? '',
                        (int) $rawValue,
                        (int) $asset->fields['entities_id'],
                    );
                }
            }

            $history = $DB->request([
                'SELECT' => [new QueryExpression('MAX(UNIX_TIMESTAMP(date_mod)) AS changed_at')],
                'FROM' => Log::getTable(),
                'WHERE' => ['items_id' => $asset->getID(), 'itemtype' => $asset::class, 'id_search_option' => $id],
            ])->current();
            $modifiedAt = $history['changed_at'] ?? null;
            $result[$id] = [
                'value' => self::readValue($field, $rawValue, (int) $asset->fields['entities_id']),
                'modified_at' => $modifiedAt === null ? null : (int) $modifiedAt,
                'kind' => $field['kind'],
            ];
        }
        return $result;
    }

    private static function itemValue(array $allowed, string $type, int $id, int $entity): ?array
    {
        global $DB;

        if ($id <= 0)
        {
            return null;
        }
        $table = self::itemTable($allowed, $type);
        $columns = $DB->listFields($table);
        $labelColumn = isset($columns['completename']) ? 'completename' : 'name';
        $row = $DB->request([
            'FROM' => $table,
            'WHERE' => ['id' => $id] + self::itemScope($type, $table, $entity),
        ])->current();
        if (!$row || trim((string) $row[$labelColumn]) === '')
        {
            throw new SyncException('reference_unavailable', 422);
        }

        $value = ['itemtype' => $type, 'label' => $row[$labelColumn]];
        if (!empty($row['serial']))
        {
            $value['serial'] = trim((string) $row['serial']);
        }
        return $value;
    }

    private static function lookupItem(array $allowed, mixed $value, int $entity): array
    {
        global $DB;

        if ($value === null || $value === '')
        {
            return ['', 0];
        }
        if (is_scalar($value) && count($allowed) === 1)
        {
            $value = ['itemtype' => reset($allowed), 'label' => SyncValue::forType($value, 'text')];
        }
        $type = is_array($value) ? ($value['itemtype'] ?? null) : null;
        if (!is_string($type))
        {
            throw new SyncException('invalid_reference_value', 422);
        }
        $table = self::itemTable($allowed, $type);
        $columns = $DB->listFields($table);
        $key = isset($columns['serial']) && is_string($value['serial'] ?? null) ? 'serial'
            : (isset($columns['completename']) ? 'completename' : 'name');
        $label = $key === 'serial' ? $value['serial'] : ($value['label'] ?? null);
        if (!is_string($label) || trim($label) === '')
        {
            throw new SyncException('invalid_reference_value', 422);
        }
        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => [$key => $label] + self::itemScope($type, $table, $entity),
            'LIMIT' => 2,
        ]);
        if (count($rows) !== 1)
        {
            throw new SyncException('reference_unavailable', 422);
        }
        return [$type, (int) $rows->current()['id']];
    }

    private static function itemTable(array $allowed, string $type): string
    {
        global $DB;
        if (!in_array($type, $allowed, true) || !is_subclass_of($type, CommonDBTM::class))
        {
            throw new SyncException('invalid_reference_value', 422);
        }
        $table = $type::getTable();
        if ($table === '' || (!$DB->fieldExists($table, 'name') && !$DB->fieldExists($table, 'completename')))
        {
            throw new SyncException('field_unavailable', 422);
        }
        return $table;
    }

    private static function itemScope(string $type, string $table, int $entity): array
    {
        global $DB;
        $scope = self::referenceScope($table, $entity);
        if ($DB->fieldExists($table, 'is_template'))
        {
            $scope['is_template'] = 0;
        }
        if (is_subclass_of($type, Asset::class) && $DB->fieldExists($table, 'assets_assetdefinitions_id'))
        {
            $scope['assets_assetdefinitions_id'] = $type::getDefinition()->getID();
        }
        return $scope;
    }

    private static function readValue(array $field, mixed $value, int $entity): mixed
    {
        if ($field['reference_table'] === null)
        {
            // GLPI represents an unknown date as NULL or, in some older rows, a zero date.
            if (in_array($field['kind'], ['date', 'datetime'], true) && is_string($value) && str_starts_with($value, '0000-00-00'))
            {
                return null;
            }
            return SyncValue::forType($value, $field['kind']);
        }
        if ($field['multiple'])
        {
            $ids = is_array($value) ? $value : json_decode((string) $value, true);
            return is_array($ids) ? array_map(
                static fn($id) => self::referenceName($field['reference_table'], (int) $id, $entity),
                $ids,
            ) : [];
        }
        return self::referenceName($field['reference_table'], (int) $value, $entity);
    }

    private static function referenceName(string $table, int $id, int $entity): ?string
    {
        global $DB;

        if ($id < 1)
        {
            return null;
        }
        $hasPath = $DB->fieldExists($table, 'completename');
        $hasName = $DB->fieldExists($table, 'name');
        $columns = array_keys(array_filter(['completename' => $hasPath, 'name' => $hasName]));
        $row = $DB->request([
            'SELECT' => $columns,
            'FROM' => $table,
            'WHERE' => ['id' => $id] + self::referenceScope($table, $entity, allowInherited: true),
        ])->current();
        // Use the full path where possible so identical leaf labels can still be distinguished.
        $path = $row['completename'] ?? '';
        $label = $path !== '' ? $path : ($row['name'] ?? null);
        if (!is_string($label) || trim($label) === '')
        {
            throw new SyncException('reference_unavailable', 422);
        }
        return $label;
    }

    private static function referenceScope(string $table, int $entity, bool $allowInherited = false): array
    {
        global $DB;

        $scope = [];
        if ($table === Entity::getTable())
        {
            // An entity reference must never wander outside the exact routed entity.
            $scope['RAW'] = ['id' => $entity];
        }
        elseif ($DB->fieldExists($table, 'entities_id'))
        {
            // GLPI includes ancestor dropdown entries marked available to child entities.
            $recursive = $allowInherited && $DB->fieldExists($table, 'is_recursive');
            $scope = getEntitiesRestrictCriteria($table, '', $entity, $recursive);
        }
        if ($DB->fieldExists($table, 'is_deleted'))
        {
            $scope['is_deleted'] = 0;
        }
        return $scope;
    }

    private static function referenceId(string $table, mixed $value, int $entity): int
    {
        global $DB;

        if ($value === null || $value === '')
        {
            return 0;
        }
        if (!is_string($value))
        {
            throw new SyncException('invalid_reference_value', 422);
        }
        $nameColumn = $DB->fieldExists($table, 'completename') ? 'completename' : 'name';
        $query = [
            'SELECT' => ['id'],
            'FROM' => $table,
            'LIMIT' => 2,
        ];
        $scope = self::referenceScope($table, $entity, allowInherited: true);
        $rows = $DB->request($query + ['WHERE' => [$nameColumn => $value] + $scope]);
        if (count($rows) === 0 && $nameColumn === 'completename' && $DB->fieldExists($table, 'name'))
        {
            // Text may contain only the leaf label. Accept it only if one visible row matches.
            $rows = $DB->request($query + ['WHERE' => ['name' => $value] + $scope]);
        }
        if (count($rows) !== 1)
        {
            throw new SyncException('reference_unavailable', 422);
        }
        return (int) $rows->current()['id'];
    }

    public static function prepare(string $itemtype, int $entity, string $serial, array $entries): array
    {
        global $DB;

        $native = [];
        $custom = [];
        $expected = [];
        $notes = null;
        foreach ($entries as $entry)
        {
            if (!is_array($entry) || !is_array($entry['field'] ?? null) || !array_key_exists('value', $entry))
            {
                throw new SyncException('invalid_field_value', 400);
            }
            $field = self::resolve($itemtype, $entry['field']);
            $value = $entry['value'];
            if ($field['kind'] === 'notes')
            {
                $notes = SyncNotes::normalize($value);
                continue;
            }
            if (!$field['can_write'])
            {
                if ($field['column'] === 'serial' && $value === $serial)
                {
                    continue;
                }
                throw new SyncException('field_read_only', 422);
            }
            $value = $field['reference_table'] === null ? SyncValue::forType($value, $field['kind'])
                : self::prepareReference($field, $entry['field'], $value, $entity);
            if ($field['item_reference'] !== null)
            {
                self::checkContainerEntity($field['container'], $entity);
                $allowed = $field['item_reference']['types'];
                [$type, $id] = self::lookupItem($allowed, $value, $entity);
                $containerId = $field['container']->getID();
                $custom[$containerId][$field['item_reference']['type_column']] = $type;
                $custom[$containerId][$field['column']] = $id;
                $expected[$field['id']] = self::itemValue($allowed, $type, $id, $entity);
                continue;
            }
            if ($value === null && $field['kind'] === 'text'
                && ($DB->getField($field['table'], $field['column'])['Null'] ?? 'YES') === 'NO')
            {
                $value = '';
            }
            if ($field['native_custom'] && $value === null)
            {
                // The native model uses isset() to detect supplied custom fields.
                $value = '';
            }

            if ($field['container'] !== null)
            {
                self::checkContainerEntity($field['container'], $entity);
                $containerId = $field['container']->getID();
                $custom[$containerId][$field['column']] = $value;
            }
            else
            {
                $native[$field['column']] = $value;
            }
            // Compare the update with the destination's own representation after it is written.
            $portableValue = $field['multiple'] && is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            $expected[$field['id']] = self::readValue($field, $portableValue, $entity);
        }
        return ['native' => $native, 'custom' => $custom, 'expected' => $expected, 'notes' => $notes];
    }

    private static function prepareReference(array $field, array $descriptor, mixed $input, int $entity): int|array
    {
        try
        {
            $value = SyncValue::forType($input, $field['kind']);
            if (!$field['multiple'])
            {
                return self::referenceId($field['reference_table'], $value, $entity);
            }
            $ids = [];
            foreach ($value as $name)
            {
                $ids[] = self::referenceId($field['reference_table'], $name, $entity);
            }
            return $ids;
        }
        catch (SyncException $problem)
        {
            $message = $problem->getMessage() === 'reference_unavailable'
                ? 'GLPI A could not resolve a mapped dropdown value in the route entity.'
                : 'GLPI A could not convert the attempted dropdown value.';
            throw SyncProblem::dropdown($message, 'a', $descriptor, $input);
        }
    }

    public static function checkContainerEntity(PluginFieldsContainer $container, int $entity): void
    {
        $ownerEntity = (int) $container->fields['entities_id'];
        if ($entity === $ownerEntity)
        {
            return;
        }
        if (empty($container->fields['is_recursive'])
            || !in_array($entity, array_map('intval', getSonsOf(Entity::getTable(), $ownerEntity)), true))
        {
            throw new SyncException('field_unavailable', 422);
        }
    }

    public static function writeCustom(CommonDBTM $asset, array $changes): void
    {
        foreach ($changes as $containerId => $values)
        {
            $container = new PluginFieldsContainer();
            if (!$container->getFromDB($containerId))
            {
                throw new SyncException('field_unavailable', 422);
            }
            $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            if (PluginFieldsProfile::getRightOnContainer($profileId, $containerId) <= READ)
            {
                throw new SyncException('field_read_only', 403);
            }

            $input = [
                'plugin_fields_containers_id' => $containerId,
                'items_id' => $asset->getID(),
                'itemtype' => $asset::class,
            ] + $values;
            if (!PluginFieldsContainer::validateValues($input, $asset::class, true))
            {
                throw new SyncException('custom_field_write_failed', 422);
            }

            self::prepareContainerInput($asset, $containerId, $input, $values);

            // Fields' convenience method writes its own history with values. Save through the same
            // container model so the controller can write one history entry per changed field.
            $model = PluginFieldsContainer::getClassname($asset::class, $container->fields['name']);
            $record = new $model();
            $criteria = ['items_id' => $asset->getID(), 'itemtype' => $asset::class,
                'plugin_fields_containers_id' => $containerId];
            if ($record->getFromDBByCrit($criteria))
            {
                $written = $record->update(['id' => $record->getID()] + $input, false);
            }
            else
            {
                $written = $record->add($input, [], false);
            }
            if (!$written)
            {
                throw new SyncException('custom_field_write_failed', 422);
            }
        }
    }

    private static function prepareContainerInput(CommonDBTM $asset, int $containerId, array &$input, array $values): void
    {
        $overrides = array_column(PluginFieldsStatusOverride::getOverridesForItem($containerId, $asset), null, 'plugin_fields_fields_id');
        $definitions = (new PluginFieldsField())->find(['plugin_fields_containers_id' => $containerId, 'is_active' => 1]);
        foreach ($definitions as $definition)
        {
            $column = match ($definition['type']) {
                'dropdown' => 'plugin_fields_' . $definition['name'] . 'dropdowns_id',
                'glpi_item' => 'items_id_' . $definition['name'],
                default => $definition['name'],
            };
            if (!array_key_exists($column, $values))
            {
                continue;
            }

            $override = $overrides[$definition['id']] ?? [];
            if (!empty($override['is_readonly'] ?? $definition['is_readonly']))
            {
                throw new SyncException('field_read_only', 403);
            }
            if (!empty($definition['multiple']))
            {
                $input[$column] = json_encode($values[$column], JSON_THROW_ON_ERROR);
            }
        }
    }

    public static function write(CommonDBTM $asset, array $entries): array
    {
        $descriptors = array_column($entries, 'field');
        $before = self::read($asset, $descriptors);
        $changes = self::prepare($asset::class, (int) $asset->fields['entities_id'], $asset->fields['serial'], $entries);

        // Avoid writing when normalized inputs already match the stored values.
        $expected = $changes['expected'];
        if ($changes['notes'] !== null)
        {
            $expected[200] = SyncNotes::normalize(array_merge($before[200]['value'], $changes['notes']));
        }
        $hasChanges = false;
        foreach ($expected as $id => $value)
        {
            if ($before[$id]['value'] !== $value)
            {
                $hasChanges = true;
                break;
            }
        }
        if (!$hasChanges)
        {
            return $expected;
        }

        if ($changes['native'] !== []
            && !$asset->update(['id' => $asset->getID(), '_skip_rules' => true] + $changes['native'], false))
        {
            throw new SyncException('asset_update_failed', 422);
        }
        self::writeCustom($asset, $changes['custom']);
        if ($changes['notes'] !== null)
        {
            $changes['expected'][200] = SyncNotes::append($asset, $changes['notes']);
        }

        if (!$asset->getFromDB($asset->getID()))
        {
            throw new SyncException('asset_update_failed', 422);
        }
        $after = self::read($asset, $descriptors);
        $hasChanges = false;
        foreach (array_keys($changes['expected']) as $id)
        {
            if ($before[$id]['value'] === $after[$id]['value'])
            {
                continue;
            }
            $hasChanges = true;
            // GLPI uses the scoped local sync account for attribution.
            if ($asset->dohistory)
            {
                SyncHistory::record($asset, $id, $before[$id], $after[$id]);
            }
        }
        if ($hasChanges && array_key_exists('date_mod', $asset->fields)
            && $asset->fields['date_mod'] !== $_SESSION['glpi_currenttime'])
        {
            // Notes and Fields containers can change without touching the parent asset.
            $asset->fields['date_mod'] = $_SESSION['glpi_currenttime'];
            if (!$asset->updateInDB(['date_mod']))
            {
                throw new SyncException('asset_update_failed', 500);
            }
        }
        return $changes['expected'];
    }
}
