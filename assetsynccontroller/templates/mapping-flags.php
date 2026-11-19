<?php

use GlpiPlugin\Assetsynccontroller\MappingFields;

?>
<section class="border-top pt-3" aria-labelledby="mapping-flags-heading">
    <h2 class="card-title" id="mapping-flags-heading">Flags from GLPI B (optional)</h2>
    <p class="text-secondary">Leave either or both fields blank. Sync sets only the selected flags to Yes on GLPI B.</p>

    <?php if (!$hasAssetType): ?>
        <p class="mb-0">Select a route and asset type to configure these flags.</p>
    <?php elseif ($agentFields !== [] && $canManageMappings): ?>
        <?php if ($flagChoices === []): ?>
            <p>No GLPI B Yes/No fields were found for this asset type.</p>
        <?php endif; ?>
        <?php if ($flagChoices !== [] || $savedFlags !== []): ?>
            <form method="post" action="<?= $pageUrl ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                <input type="hidden" name="mapping_action" value="save_flags">
                <input type="hidden" name="route_id" value="<?= $routeId ?>">
                <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
                <div class="row g-3 mb-3">
                    <?php foreach ($flagLabels as $key => $label): ?>
                        <?php $fieldId = 'mapping-flag-' . $key; ?>
                        <div class="col-md-6">
                            <label class="form-label" for="<?= $fieldId ?>"><?= htmlescape($label) ?></label>
                            <select class="form-select" name="agent_flag_id[<?= $key ?>]" id="<?= $fieldId ?>"
                                    data-minimum-results-for-search="0">
                                <option value="">None</option>
                                <?php foreach (MappingFields::bySource($flagChoices) as $source => $fields): ?>
                                    <optgroup label="<?= htmlescape($source) ?>">
                                        <?php foreach ($fields as $id => $field): ?>
                                            <option value="<?= $id ?>"<?= $id === ($flagSelections[$key] ?? null) ? ' selected' : '' ?>>
                                                <?= htmlescape($field['name'] . ' (#' . $id . ')') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($savedFlags[$key]) && !isset($flagSelections[$key])): ?>
                                <div class="form-text"><?= htmlescape($savedFlags[$key]['name']) ?> is unavailable. Select a replacement or choose None.</div>
                            <?php endif; ?>
                        </div>
                        <?= Html::jsAdaptDropdown($fieldId, ['width' => '100%']) ?>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn btn-primary">Save flags</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <dl class="mb-0">
            <?php foreach ($flagLabels as $key => $label): ?>
                <dt><?= htmlescape($label) ?></dt>
                <dd><?= htmlescape($savedFlags[$key]['name'] ?? 'None') ?></dd>
            <?php endforeach; ?>
        </dl>
        <?php if ($canManageMappings && $routeIsVerified): ?>
            <p class="mb-0">Select Load fields to edit these flags.</p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($hasAssetType && $canManageMappings && $savedFlags !== []): ?>
        <?php // Clearing the saved selections only changes this route's local settings. ?>
        <form method="post" action="<?= $pageUrl ?>" class="mt-3">
            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
            <input type="hidden" name="mapping_action" value="clear_flags">
            <input type="hidden" name="route_id" value="<?= $routeId ?>">
            <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
            <button type="submit" class="btn btn-outline-secondary">Clear both flags</button>
        </form>
    <?php endif; ?>
</section>
