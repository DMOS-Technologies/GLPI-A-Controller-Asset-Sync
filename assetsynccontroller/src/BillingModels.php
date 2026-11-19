<?php

namespace GlpiPlugin\Assetsynccontroller;

use Computer;

final class BillingModels
{
    public const REFERENCE_LABELS = [
        'monthly' => [
            'Lenovo Thinkpad L14 Gen 5',
            'Lenovo ThinkPad L14 Gen 6',
            'Lenovo ThinkPad L14 Gen 4',
            'Lenovo ThinkPad L14 16GB',
            'Lenovo ThinkPad L14 32GB',
            'Lenovo ThinkPad L14 64GB',
            'ThinkPad L14 Gen 4',
            'ThinkPad L14 Gen 5',
            'Lenovo ThinkPad L14',
            'Lenovo Thinkpad P1 Gen 7',
            'Lenovo ThinkPad P1',
        ],
        'annual' => ['Lenovo ThinkStation P7'],
    ];

    /** @return array<string, string> Representative model key and exact label. */
    public static function getOptions(array $modelField, array $entityIds): array
    {
        $sharedChoices = null;
        foreach ($entityIds as $entityId)
        {
            $entityId = (int) $entityId;
            $field = BillingAssetFields::validate(Computer::class, $modelField, $entityId, ['dropdown']);
            $choices = BillingAssetFields::dropdownOptions($field, $entityId);
            $counts = array_count_values($choices);
            $uniqueChoices = [];
            foreach ($choices as $id => $label)
            {
                if ($label !== '' && $counts[$label] === 1)
                {
                    $uniqueChoices[$label] = $field['reference_table'] . ':' . $id;
                }
            }

            // A label must identify one permitted option in every selected entity.
            $sharedChoices = $sharedChoices === null ? $uniqueChoices : array_intersect_key($sharedChoices, $uniqueChoices);
        }

        $options = [];
        foreach ($sharedChoices ?? [] as $label => $key)
        {
            $options[$key] = (string) $label;
        }
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }
}
