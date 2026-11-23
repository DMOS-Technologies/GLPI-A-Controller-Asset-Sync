<?php

$listUrl = htmlescape($routesUrl);
$formUrl = $listUrl . ($editingRoute === null ? '&amp;route_action=add' : '&amp;route_action=edit&amp;route_id=' . (int) $editingRoute['id']);
?>

<section class="card mt-3" aria-label="Routes">
    <?php if ($action === ''): ?>
        <?php if ($canManageRoutes): ?>
            <div class="card-header">
                <a class="btn btn-primary" href="<?= $listUrl ?>&amp;route_action=add">
                    <i class="ti ti-plus" aria-hidden="true"></i> Add route
                </a>
            </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">Connection name</th>
                        <th scope="col">Sync status</th>
                        <th scope="col">Setting Status</th>
                        <?php if ($canManageRoutes): ?>
                            <th scope="col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($routes) === 0): ?>
                        <tr><td colspan="<?= $canManageRoutes ? 4 : 3 ?>">No routes yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($routes as $route): ?>
                        <?php
                        $connectionName = $route['current_connection_name'] ?? $route['connection_name'];
                        $routeIsEnabled = (bool) $route['is_enabled'];
                        // A missing connection or a settings change invalidates the earlier check.
                        $verified = $route['current_connection_revision'] !== null
                            && (int) $route['current_connection_revision'] === (int) $route['connection_revision'];
                        $assetLabels = json_decode($route['asset_types'], true);
                        ?>
                        <tr>
                            <th scope="row">
                                <?= htmlescape($connectionName) ?>
                                <div class="text-secondary fw-normal">
                                    <?= htmlescape($controllerEntities[$route['controller_entity_id']]) ?>
                                    &rarr; <?= htmlescape($route['agent_entity_name']) ?>
                                </div>
                                <div class="text-secondary fw-normal"><?= htmlescape(implode(', ', $assetLabels)) ?></div>
                            </th>
                            <td>
                                <span class="badge <?= $routeIsEnabled ? 'bg-green-lt' : 'bg-secondary-lt' ?>">
                                    <?= $routeIsEnabled ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $verified ? 'bg-green-lt' : 'bg-yellow-lt' ?>">
                                    <?= $verified ? 'Verified' : 'Pending' ?>
                                </span>
                            </td>
                            <?php if ($canManageRoutes): ?>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= $listUrl ?>&amp;route_action=edit&amp;route_id=<?= (int) $route['id'] ?>">Edit</a>
                                        <a class="btn btn-sm btn-outline-danger" href="<?= $listUrl ?>&amp;route_action=delete&amp;route_id=<?= (int) $route['id'] ?>">Delete</a>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($routeToDelete !== null): ?>
        <div class="card-body">
            <h2 class="card-title">Delete route #<?= (int) $routeToDelete['id'] ?></h2>
            <p>
                <?= htmlescape($controllerEntities[$routeToDelete['controller_entity_id']]) ?>
                &rarr; <?= htmlescape($routeToDelete['agent_entity_name']) ?>
            </p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlescape($error) ?></div>
            <?php endif; ?>
            <p>Deleting this route also deletes its mappings and queue entries.</p>
            <form method="post" action="<?= $listUrl ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                <input type="hidden" name="route_action" value="delete">
                <input type="hidden" name="route_id" value="<?= (int) $routeToDelete['id'] ?>">
                <input type="hidden" name="route_token" value="<?= htmlescape($routeToken) ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <button type="submit" class="btn btn-danger">Delete route</button>
                <a href="<?= $listUrl ?>" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    <?php else: ?>
        <div class="card-body">
            <h2 class="card-title"><?= $editingRoute === null ? 'Add route' : 'Edit route #' . (int) $editingRoute['id'] ?></h2>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlescape($error) ?></div>
            <?php endif; ?>

            <?php if (count($savedConnections) === 0): ?>
                <p>Add a connection before saving a route.</p>
                <?php if (isset($sections['connections'])): ?>
                    <a href="?section=connections" class="btn btn-primary">Go to Connections</a>
                <?php endif; ?>
                <a href="<?= $listUrl ?>" class="btn btn-outline-secondary">Cancel</a>
            <?php elseif ($controllerEntities === []): ?>
                <p>No Controller A entities are available in your current GLPI scope.</p>
                <a href="<?= $listUrl ?>" class="btn btn-outline-secondary">Back to Routes</a>
            <?php elseif ($agentOptions === null): ?>
                <form method="post" action="<?= $listUrl ?>">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                    <input type="hidden" name="route_action" value="load">
                    <?php if ($editingRoute !== null): ?>
                        <input type="hidden" name="route_id" value="<?= (int) $editingRoute['id'] ?>">
                        <input type="hidden" name="route_token" value="<?= htmlescape($routeToken) ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="route-connection">Connection name</label>
                        <select class="form-select" name="connection_id" id="route-connection" required>
                            <option value="">Select a connection</option>
                            <?php foreach ($savedConnections as $savedConnection): ?>
                                <?php $id = (int) $savedConnection['id']; ?>
                                <option value="<?= $id ?>"<?= $id === $selectedConnectionId ? ' selected' : '' ?>>
                                    <?= htmlescape($savedConnection['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Load route options</button>
                    <a href="<?= $listUrl ?>" class="btn btn-outline-secondary">Cancel</a>
                </form>
            <?php else: ?>
                <div class="mb-3">
                    <strong><?= htmlescape($connection['name']) ?></strong>
                    <a href="<?= $formUrl ?>" class="ms-2">Change connection</a>
                </div>
                <form method="post" action="<?= $listUrl ?>">
                    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                    <input type="hidden" name="route_action" value="save">
                    <input type="hidden" name="connection_id" value="<?= (int) $connection['id'] ?>">
                    <?php if ($editingRoute !== null): ?>
                        <input type="hidden" name="route_id" value="<?= (int) $editingRoute['id'] ?>">
                        <input type="hidden" name="route_token" value="<?= htmlescape($routeToken) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" for="route-controller-entity">GLPI Entity from A (Controller)</label>
                        <select class="form-select" name="controller_entity_id" id="route-controller-entity" required>
                            <option value="">Select a Controller A entity</option>
                            <?php foreach ($controllerEntities as $entityId => $entityName): ?>
                                <option value="<?= $entityId ?>"<?= $entityId === $controllerEntityId ? ' selected' : '' ?>>
                                    <?= htmlescape($entityName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="route-agent-entity">GLPI Entity from B (Agent)</label>
                        <select class="form-select" name="agent_entity_id" id="route-agent-entity" required>
                            <option value="">Select an Agent B entity</option>
                            <?php foreach ($agentOptions['entities'] as $entityId => $entityName): ?>
                                <option value="<?= $entityId ?>"<?= $entityId === $agentEntityId ? ' selected' : '' ?>>
                                    <?= htmlescape($entityName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="route-status">Sync status</label>
                        <select class="form-select" name="is_enabled" id="route-status">
                            <option value="0"<?= $isEnabled === '0' ? ' selected' : '' ?>>Disabled</option>
                            <option value="1"<?= $isEnabled === '1' ? ' selected' : '' ?>>Enabled</option>
                        </select>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="form-label">Asset Types from B (select at least one)</legend>
                        <div class="row g-2">
                            <?php foreach ($agentOptions['asset_types'] as $itemType => $label): ?>
                                <div class="col-12 col-sm-6 col-lg-4">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" name="asset_types[]"
                                               value="<?= htmlescape($itemType) ?>"
                                               <?= in_array($itemType, (array) $selectedAssetTypes, true) ? 'checked' : '' ?>>
                                        <span class="form-check-label"><?= htmlescape($label) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php if ($editingRoute !== null): ?>
                        <p class="form-hint">
                            Removing an asset type deletes its mappings, flags, and queued work.
                            Selecting a different connection clears the route's mappings and flags.
                            Changing the connection or either entity restarts sync discovery for the route.
                        </p>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary"><?= $editingRoute === null ? 'Add route' : 'Save route' ?></button>
                    <a href="<?= $listUrl ?>" class="btn btn-outline-secondary">Cancel</a>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
