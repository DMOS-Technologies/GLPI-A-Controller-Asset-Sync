<?php

use GlpiPlugin\Assetsynccontroller\BillingConfiguration;
use GlpiPlugin\Assetsynccontroller\BillingModels;

$billingPageUrl = htmlescape($billingUrl);
?>

<section class="card mt-3" aria-labelledby="billing-scope-title">
    <div class="card-body">
        <h2 class="card-title" id="billing-scope-title">Billing scope</h2>
        <p>The AssetBilling automatic action calculates billing for Computers in these entities.</p>
        <?php if ($billingError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlescape($billingError) ?></div>
        <?php endif; ?>
        <?php if (!$hasFullBillingScope): ?>
            <div class="alert alert-warning">Switch to a GLPI entity scope that includes all saved billing entities to edit this configuration.</div>
        <?php endif; ?>
        <form method="post" action="<?= $billingPageUrl ?>">
            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($billingToken) ?>">
            <input type="hidden" name="billing_action" value="entities">
            <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
            <label class="form-label" for="billing-entities">GLPI A entities</label>
            <select class="form-select" name="billing_entities[]" id="billing-entities" multiple<?= $canSaveBilling ? '' : ' disabled' ?>>
                <?php foreach ($entityOptions as $entityId => $label): ?>
                    <option value="<?= $entityId ?>"<?= in_array($entityId, $billing['entities'], true) ? ' selected' : '' ?>>
                        <?= htmlescape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($canSaveBilling): ?>
                <button class="btn btn-primary mt-3" type="submit">Save entities</button>
            <?php endif; ?>
        </form>
        <?= Html::jsAdaptDropdown('billing-entities', ['width' => '100%', 'placeholder' => 'Select GLPI A entities']) ?>
    </div>
</section>

<?php if ($hasFullBillingScope && $billing['entities'] !== []): ?>
    <section class="card mt-3" aria-labelledby="hardware-billing-title">
        <div class="card-body">
            <h2 class="card-title" id="hardware-billing-title">Hardware Billing</h2>
            <form method="get" class="mb-3">
                <input type="hidden" name="section" value="billing-configuration">
                <label class="form-label" for="billing-asset-type">GLPI A asset type</label>
                <div class="d-flex flex-wrap gap-2">
                    <select class="form-select w-auto" id="billing-asset-type" name="asset_type" required>
                        <option value="">Select an asset type</option>
                        <?php foreach ($assetTypes as $type => $label): ?>
                            <option value="<?= htmlescape($type) ?>"<?= $type === $assetType ? ' selected' : '' ?>>
                                <?= htmlescape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-primary" type="submit">Load fields</button>
                </div>
            </form>
            <?php if ($assetType !== ''): ?>
                <?php
                $fieldForm = [
                    'action' => 'fields',
                    'id_prefix' => 'billing-field-',
                    'fields' => $billingFields,
                    'enable_label' => 'Enable Hardware Billing',
                    'save_label' => 'Save hardware fields',
                ];
                require __DIR__ . '/billing-fields.php';
                ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card mt-3" aria-labelledby="swsd-billing-title">
        <div class="card-body">
            <h2 class="card-title" id="swsd-billing-title">SW/SD Billing</h2>
            <?php if ($assetType === ''): ?>
                <p class="mb-0">Choose a <a href="#billing-asset-type">GLPI A asset type</a> and select Load fields.</p>
            <?php else: ?>
                <?php
                $fieldForm = [
                    'action' => 'swsd_fields',
                    'id_prefix' => 'swsd-billing-field-',
                    'fields' => $swsdBillingFields,
                    'enable_label' => 'Enable SW/SD Billing',
                    'save_label' => 'Save SW/SD fields',
                ];
                require __DIR__ . '/billing-fields.php';
                ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card mt-3" aria-labelledby="billing-models-title">
        <div class="card-body">
            <h2 class="card-title" id="billing-models-title">Model Billing Frequency</h2>
            <?php if ($modelKind === null): ?>
                <p class="mb-0">Load the Computer fields and save the Hardware Billing Model mapping to assign frequencies.</p>
            <?php elseif ($modelKind === 'dropdown' && $modelOptions === []): ?>
                <p class="text-secondary mb-0">The mapped dropdown has no model labels that occur exactly once in every selected entity.</p>
            <?php else: ?>
                <p><?= $modelKind === 'dropdown' ? 'Select the models for each frequency.' : 'Enter one exact model label per line.' ?></p>
                <?php $showReferenceLabels = $modelKind === 'text' && !isset($billing['model_labels']); ?>
                <?php if ($showReferenceLabels): ?>
                    <p class="text-secondary">The editable reference labels are shown until you save your own groups.</p>
                <?php endif; ?>
                <form method="post" action="<?= $billingPageUrl ?>">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($billingToken) ?>">
                    <input type="hidden" name="billing_action" value="models">
                    <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
                    <div class="row g-3">
                        <?php foreach (BillingConfiguration::FREQUENCIES as $frequency => $label): ?>
                            <div class="col-md-6">
                                <label class="form-label" for="billing-models-<?= $frequency ?>"><?= htmlescape($label) ?></label>
                                <?php if ($modelKind === 'dropdown'): ?>
                                    <select class="form-select" id="billing-models-<?= $frequency ?>" name="billing_models[<?= $frequency ?>][]"
                                            multiple<?= $canSaveBilling ? '' : ' disabled' ?>>
                                        <?php foreach ($modelOptions as $key => $model): ?>
                                            <?php
                                            $modelFrequency = $billing['dropdown_model_labels'][$model] ?? $billing['models'][$key] ?? null;
                                            ?>
                                            <option value="<?= htmlescape($key) ?>"<?= $modelFrequency === $frequency ? ' selected' : '' ?>>
                                                <?= htmlescape($model) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?= Html::jsAdaptDropdown('billing-models-' . $frequency, ['width' => '100%', 'placeholder' => 'Search model labels']) ?>
                                <?php else: ?>
                                    <?php
                                    $labels = $showReferenceLabels ? BillingModels::REFERENCE_LABELS[$frequency]
                                        : array_keys($billing['model_labels'], $frequency, true);
                                    ?>
                                    <textarea class="form-control" id="billing-models-<?= $frequency ?>" name="billing_model_labels[<?= $frequency ?>]"
                                              rows="6"<?= $canSaveBilling ? '' : ' readonly' ?>><?= htmlescape(implode("\n", $labels)) ?></textarea>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($canSaveBilling): ?>
                        <button class="btn btn-primary mt-3" type="submit">Save model frequencies</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
