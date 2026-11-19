<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use Glpi\Asset\Asset;
use Glpi\Asset\CustomFieldDefinition;
use Glpi\Asset\CustomFieldOption\ProfileRestrictOption;
use Glpi\RichText\RichText;
use Glpi\Search\SearchOption;
use Log;
use PluginFieldsProfile;
use Session;

final class SyncHistory
{
    public static function record(CommonDBTM $asset, int $fieldId, array $before, array $after, string $origin = self::class): void
    {
        $option = SearchOption::getOptionsForItemtype($asset::class)[$fieldId] ?? [];
        $definition = $option['field_definition'] ?? null;
        $systemName = $definition instanceof CustomFieldDefinition ? $definition->fields['system_name'] : '';
        $fieldNames = implode(' ', [
            $option['field'] ?? '',
            $option['linkfield'] ?? '',
            $option['table'] ?? '',
            $option['name'] ?? '',
            $systemName,
        ]);

        // The history can outlive the field, so exclude credentials and URLs before storing anything.
        $oldValue = '';
        $newValue = '';
        if ($option !== [] && ($option['datatype'] ?? '') !== 'weblink'
            && !preg_match('/password|passphrase|secret|token|credential|api[ _-]*key|private[ _-]*key|access[ _-]*key|license[ _-]*key|url/i', $fieldNames))
        {
            $oldValue = self::formatValue($before['value'], $before['kind'], $option['datatype'] ?? '');
            $newValue = self::formatValue($after['value'], $after['kind'], $option['datatype'] ?? '');
            if ($oldValue === '' || $newValue === '')
            {
                $oldValue = '';
                $newValue = '';
            }
        }

        // Keep the real search option ID so bidirectional sync can still date this individual field.
        if (!Log::history($asset->getID(), $asset::class, [$fieldId, $oldValue, $newValue], $origin, Log::HISTORY_PLUGIN))
        {
            throw new SyncException('asset_history_failed', 500);
        }
    }

    public static function getTypeName(int $nb = 0): string
    {
        return __('Asset synchronization', 'assetsynccontroller');
    }

    // GLPI calls this for plugin history actions. The Historical tab renders the return value as HTML.
    public static function getHistoryEntry(array $row): string
    {
        $itemtype = $row['itemtype'];
        $option = SearchOption::getCleanedOptions($itemtype)[$row['id_search_option']] ?? null;
        if (!is_array($option) || !empty($option['nodisplay']) || !self::canSeeField($row, $option))
        {
            $fallback = sprintf(__s('Field #%d', 'assetsynccontroller'), (int) $row['id_search_option']);
            return self::withFieldLabel($fallback, __s('A synchronized field was updated.', 'assetsynccontroller'));
        }

        $fieldName = htmlescape($option['name']);
        if (!str_starts_with($row['old_value'], '=') || !str_starts_with($row['new_value'], '='))
        {
            $change = sprintf(__s('%s was updated. Values are hidden.', 'assetsynccontroller'), $fieldName);
        }
        else
        {
            $change = sprintf(
                __s('%1$s changed from %2$s to %3$s.', 'assetsynccontroller'),
                $fieldName,
                '<del>' . htmlescape(substr($row['old_value'], 1)) . '</del>',
                '<ins>' . htmlescape(substr($row['new_value'], 1)) . '</ins>',
            );
        }

        return self::withFieldLabel($fieldName, $change);
    }

    private static function withFieldLabel(string $escapedFieldName, string $change): string
    {
        // GLPI's plugin callback supplies Update only. The browser can use this label in the Field cell.
        return '<span data-asset-sync-history-field="' . $escapedFieldName . '">' . $change . '</span>';
    }

    private static function canSeeField(array $row, array $option): bool
    {
        $itemtype = $row['itemtype'];
        $fieldId = $row['id_search_option'];
        if (SyncNotes::isField(['id' => $fieldId] + $option))
        {
            return Session::haveRight($itemtype::$rightname, READNOTE);
        }

        if (str_starts_with($option['table'], 'glpi_plugin_fields_'))
        {
            try
            {
                $field = SyncFields::resolve($itemtype, ['id' => $fieldId] + $option);
                $container = $field['container'];
                $asset = getItemForItemtype($itemtype);
                if ($container === null || !$asset instanceof CommonDBTM || !$asset->getFromDB($row['items_id']))
                {
                    return false;
                }
                SyncFields::checkContainerEntity($container, (int) $asset->fields['entities_id']);
            }
            catch (SyncException)
            {
                return false;
            }

            $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
            return PluginFieldsProfile::getRightOnContainer($profileId, $container->getID()) >= READ;
        }

        $definition = $option['field_definition'] ?? null;
        if ($definition instanceof CustomFieldDefinition)
        {
            return empty($definition->getFieldType()->getOptionValues()['hidden']);
        }

        if (is_subclass_of($itemtype, Asset::class))
        {
            // GLPI also allows a custom asset definition to hide built-in fields by profile.
            $column = $option['table'] === $itemtype::getTable() ? $option['field'] : ($option['linkfield'] ?? '');
            foreach ($itemtype::getDefinition()->getDecodedFieldsField() as $field)
            {
                if ($field['key'] === $column && !empty($field['field_options']['hidden']))
                {
                    $settings = new CustomFieldDefinition();
                    $settings->fields['field_options'] = $field['field_options'];
                    return !(new ProfileRestrictOption($settings, 'hidden', '', false))->getValue();
                }
            }
        }

        return true;
    }

    private static function formatValue(mixed $value, string $kind, string $datatype): string
    {
        if ($kind === 'notes')
        {
            $count = count($value);
            $value = sprintf(_n('%s note', '%s notes', $count, 'assetsynccontroller'), $count);
        }
        elseif (is_bool($value))
        {
            $value = $value ? __('Yes') : __('No');
        }
        elseif ($kind === 'item' && is_array($value))
        {
            $value = $value['label'] ?? '';
        }
        elseif (is_array($value))
        {
            $value = implode(', ', $value);
        }
        $value = trim((string) $value);
        // A URL pasted into an ordinary text field can still carry credentials.
        if (preg_match('~https?://[^/\s]+@|[?&#](?:[^=&#\s]*(?:password|token|secret|credential)|api[_-]?key)=~i', $value))
        {
            return '';
        }
        if ($datatype === 'text')
        {
            $value = RichText::getTextFromHtml($value, false);
        }
        if ($value === '')
        {
            $value = __('(empty)', 'assetsynccontroller');
        }
        if (mb_strlen($value) > 254)
        {
            $value = mb_substr($value, 0, 253) . '…';
        }

        // GLPI drops values that look like JSON. The prefix keeps plain strings such as "[]" intact.
        return '=' . $value;
    }
}
