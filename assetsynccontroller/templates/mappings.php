<?php

use GlpiPlugin\Assetsynccontroller\MappingFields;

$pageUrl = htmlescape($mappingsUrl);
?>

<section class="card mt-3" aria-label="Mappings">
    <div class="card-body">
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlescape($error) ?></div>
        <?php endif; ?>

        <?php if ($availableRoutes === []): ?>
            <p>No saved routes are available in your current GLPI scope.</p>
            <?php if (isset($sections['routes'])): ?>
                <a class="btn btn-primary" href="?section=routes">Go to Routes</a>
            <?php endif; ?>
        <?php else: ?>
            <form method="get" class="mb-3">
                <input type="hidden" name="section" value="mappings">
                <label class="form-label" for="mapping-route">Route</label>
                <div class="d-flex flex-wrap gap-2">
                    <select class="form-select w-auto" id="mapping-route" name="route_id" required>
                        <option value="">Select a saved route</option>
                        <?php foreach ($availableRoutes as $id => $savedRoute): ?>
                            <?php $name = $savedRoute['current_connection_name'] ?? $savedRoute['connection_name']; ?>
                            <option value="<?= $id ?>"<?= $id === $routeId ? ' selected' : '' ?>>
                                #<?= $id ?> · <?= htmlescape($name) ?> ·
                                <?= htmlescape($savedRoute['controller_entity_name']) ?> → <?= htmlescape($savedRoute['agent_entity_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline-primary">Select route</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($route !== null): ?>
            <?php $canLoadFields = $canManageMappings && $routeIsVerified; ?>
            <?php if (!$routeIsVerified && $error === ''): ?>
                <div class="alert alert-warning">This route is Pending. Select a verified route to load fields.</div>
            <?php endif; ?>
            <form method="<?= $canLoadFields ? 'post' : 'get' ?>" action="<?= $pageUrl ?>" class="mb-3">
                <?php if ($canLoadFields): ?>
                    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                    <input type="hidden" name="mapping_action" value="load">
                <?php else: ?>
                    <input type="hidden" name="section" value="mappings">
                <?php endif; ?>
                <input type="hidden" name="route_id" value="<?= $routeId ?>">
                <label class="form-label" for="mapping-asset-type">Asset type</label>
                <div class="d-flex flex-wrap gap-2">
                    <select class="form-select w-auto" name="asset_type" id="mapping-asset-type" required>
                        <option value="">Select an asset type from this route</option>
                        <?php foreach ($routeAssetTypes as $itemType => $label): ?>
                            <option value="<?= htmlescape($itemType) ?>"<?= $itemType === $assetType ? ' selected' : '' ?>>
                                <?= htmlescape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary"><?= $canLoadFields ? 'Load fields' : 'Show mappings' ?></button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($controllerFields !== [] && $agentFields !== []): ?>
            <form method="post" action="<?= $pageUrl ?>" class="border-top pt-3 mb-3">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                <input type="hidden" name="mapping_action" value="save">
                <input type="hidden" name="route_id" value="<?= $routeId ?>">
                <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
                <div class="row g-3 mb-3">
                    <div class="col-md">
                        <label class="form-label" for="mapping-controller-field">GLPI A field</label>
                        <select class="form-select" name="controller_option_id" id="mapping-controller-field"
                                data-minimum-results-for-search="0" required>
                            <option value="">Select a GLPI A field</option>
                            <?php foreach (MappingFields::bySource($controllerFields) as $source => $fields): ?>
                                <optgroup label="<?= htmlescape($source) ?>">
                                    <?php foreach ($fields as $id => $field): ?>
                                        <option value="<?= $id ?>"<?= $id === $controllerOptionId ? ' selected' : '' ?>>
                                            <?= htmlescape($field['name'] . ' (#' . $id . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md">
                        <label class="form-label" for="mapping-direction">Direction</label>
                        <select class="form-select" name="direction" id="mapping-direction" required>
                            <?php foreach ($directions as $key => $label): ?>
                                <option value="<?= $key ?>"<?= $key === $direction ? ' selected' : '' ?>><?= htmlescape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md">
                        <label class="form-label" for="mapping-agent-field">GLPI B field</label>
                        <select class="form-select" name="agent_option_id" id="mapping-agent-field"
                                data-minimum-results-for-search="0" required>
                            <option value="">Select a GLPI B field</option>
                            <?php foreach (MappingFields::bySource($agentFields) as $source => $fields): ?>
                                <optgroup label="<?= htmlescape($source) ?>">
                                    <?php foreach ($fields as $id => $field): ?>
                                        <option value="<?= $id ?>"<?= $id === $agentOptionId ? ' selected' : '' ?>>
                                            <?= htmlescape($field['name'] . ' (#' . $id . ')') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary">Save mapping</button>
            </form>
            <?php
            // Reuse GLPI's searchable dropdowns and their group-aware matcher.
            echo Html::jsAdaptDropdown('mapping-controller-field', ['width' => '100%']);
            echo Html::jsAdaptDropdown('mapping-agent-field', ['width' => '100%']);
            ?>
        <?php endif; ?>
        <?php require __DIR__ . '/mapping-flags.php'; ?>
    </div>

    <?php if ($hasAssetType): ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">GLPI A field</th>
                        <th scope="col">Direction</th>
                        <th scope="col">GLPI B field</th>
                        <?php if ($canManageMappings): ?><th scope="col">Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($savedMappings) === 0): ?>
                        <tr><td colspan="<?= $canManageMappings ? 4 : 3 ?>">No mappings saved for this route and asset type.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($savedMappings as $mapping): ?>
                        <?php
                        // Display the saved labels even if the remote field is later renamed.
                        $a = json_decode($mapping['controller_field'], true);
                        $b = json_decode($mapping['agent_field'], true);
                        ?>
                        <tr>
                            <td>
                                <?= htmlescape($a['name'] . ' (#' . $mapping['controller_option_id'] . ')') ?>
                                <div class="text-secondary"><?= htmlescape(MappingFields::sourceLabel($a)) ?></div>
                            </td>
                            <td><?= htmlescape($directions[$mapping['direction']]) ?></td>
                            <td>
                                <?= htmlescape($b['name'] . ' (#' . $mapping['agent_option_id'] . ')') ?>
                                <div class="text-secondary"><?= htmlescape(MappingFields::sourceLabel($b)) ?></div>
                            </td>
                            <?php if ($canManageMappings): ?>
                                <td>
                                    <form method="post" action="<?= $pageUrl ?>">
                                        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                                        <input type="hidden" name="mapping_action" value="remove">
                                        <input type="hidden" name="route_id" value="<?= $routeId ?>">
                                        <input type="hidden" name="asset_type" value="<?= htmlescape($assetType) ?>">
                                        <input type="hidden" name="controller_option_id" value="<?= (int) $mapping['controller_option_id'] ?>">
                                        <input type="hidden" name="agent_option_id" value="<?= (int) $mapping['agent_option_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove mapping</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
