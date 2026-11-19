<?php

use GlpiPlugin\Assetsynccontroller\MappingFields;

// Both billing sections use the same GLPI fields but save their selections separately.
$savedFields = $billing[$fieldForm['action']][$assetType] ?? [];
?>

<form method="post" action="<?= $billingPageUrl ?>">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($billingToken) ?>">
    <input type="hidden" name="billing_action" value="<?= htmlescape($fieldForm['action']) ?>">
    <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
    <?php if ($assetType === Computer::class): ?>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="<?= htmlescape($fieldForm['id_prefix']) ?>enabled"
                   name="billing_enabled" value="1"<?= !empty($billing['enabled'][$fieldForm['action']]) ? ' checked' : '' ?><?= $canSaveBilling ? '' : ' disabled' ?>>
            <label class="form-check-label" for="<?= htmlescape($fieldForm['id_prefix']) ?>enabled"><?= htmlescape($fieldForm['enable_label']) ?></label>
        </div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($fieldForm['fields'] as $key => [$label, $kind]): ?>
            <?php
            $fieldId = $fieldForm['id_prefix'] . $key;
            $availableFields = $fieldOptions[$kind] ?? [];
            $savedField = $savedFields[$key] ?? null;
            $selectedId = $savedField['id'] ?? null;
            $currentField = $availableFields[$selectedId] ?? [];
            // Option IDs can be reused after asset definitions or custom fields are changed.
            $missing = $savedField !== null
                && ($currentField === []
                    || $currentField['table'] !== $savedField['table']
                    || $currentField['field'] !== $savedField['field']
                    || ($currentField['linkfield'] ?? null) !== ($savedField['linkfield'] ?? null));
            ?>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="<?= htmlescape($fieldId) ?>"><?= htmlescape($label) ?></label>
                <select class="form-select" id="<?= htmlescape($fieldId) ?>" name="billing_field[<?= htmlescape($key) ?>]"
                        data-minimum-results-for-search="0"<?= $canSaveBilling ? '' : ' disabled' ?>>
                    <option value="">Unconfigured</option>
                    <?php if ($missing): ?>
                        <option value="saved" selected><?= htmlescape($savedField['name']) ?> (unavailable)</option>
                    <?php endif; ?>
                    <?php foreach (MappingFields::bySource($availableFields) as $source => $fields): ?>
                        <optgroup label="<?= htmlescape($source) ?>">
                            <?php foreach ($fields as $id => $field): ?>
                                <option value="<?= $id ?>"<?= !$missing && $id === $selectedId ? ' selected' : '' ?>>
                                    <?= htmlescape($field['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?= Html::jsAdaptDropdown($fieldId, ['width' => '100%']) ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($canSaveBilling): ?>
        <button class="btn btn-primary mt-3" type="submit"><?= htmlescape($fieldForm['save_label']) ?></button>
    <?php endif; ?>
</form>
