<?php

// A route without mappings may still have queued assets available to cancel.
$syncActions = $mappedAssetTypes === [] ? [] : [
    'all' => ['title' => 'Sync All Assets', 'detail' => 'Queue every eligible asset of every mapped type on this route.'],
    'type' => ['title' => 'Sync by Asset Type', 'detail' => 'Queue all eligible assets of one mapped type.'],
    'asset' => ['title' => 'Sync by Asset ID', 'detail' => 'Queue one or more GLPI A assets of the selected type.'],
];
?>
<button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#asc-sync-actions">
    More actions
</button>
<div class="modal modal-blur fade" id="asc-sync-actions" tabindex="-1" aria-labelledby="asc-sync-actions-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="asc-sync-actions-title">More actions</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Manage the scheduled sync queue for route #<?= $routeId ?>.</p>
                <?php foreach ($syncActions as $scope => $action): ?>
                    <form method="post" action="<?= htmlescape($syncUrl) ?>" class="border-top pt-3 mb-3"
                          aria-labelledby="asc-sync-<?= $scope ?>-title">
                        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($retryToken) ?>">
                        <input type="hidden" name="sync_action" value="queue">
                        <input type="hidden" name="sync_scope" value="<?= $scope ?>">
                        <input type="hidden" name="route_id" value="<?= $routeId ?>">
                        <h3 id="asc-sync-<?= $scope ?>-title"><?= htmlescape($action['title']) ?></h3>
                        <p class="text-secondary"><?= htmlescape($action['detail']) ?></p>
                        <?php if ($scope !== 'all'): ?>
                            <div class="mb-3">
                                <label class="form-label" for="asc-sync-<?= $scope ?>-type">Asset type</label>
                                <select class="form-select" id="asc-sync-<?= $scope ?>-type" name="asset_type" required>
                                    <option value="">Select an asset type</option>
                                    <?php foreach ($mappedAssetTypes as $type => $label): ?>
                                        <option value="<?= htmlescape($type) ?>"><?= htmlescape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if ($scope === 'asset'): ?>
                            <div class="mb-3">
                                <label class="form-label" for="asc-sync-asset-ids">Asset IDs in GLPI A</label>
                                <textarea class="form-control" id="asc-sync-asset-ids" name="asset_ids" rows="3"
                                          aria-describedby="asc-sync-asset-ids-hint" required></textarea>
                                <div class="form-hint" id="asc-sync-asset-ids-hint">
                                    Separate IDs with commas, spaces, or new lines.
                                </div>
                            </div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?= htmlescape($action['title']) ?></button>
                    </form>
                <?php endforeach; ?>
                <?php if ($canCancelQueuedAssets): ?>
                    <form method="post" action="<?= htmlescape($syncUrl) ?>" class="border-top pt-3"
                          aria-labelledby="asc-sync-cancel-title">
                        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($retryToken) ?>">
                        <input type="hidden" name="sync_action" value="cancel">
                        <input type="hidden" name="route_id" value="<?= $routeId ?>">
                        <h3 id="asc-sync-cancel-title">Cancel all queued assets</h3>
                        <p class="text-secondary">Assets already processing on GLPI B will continue.</p>
                        <label class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" name="confirm_cancel" value="1" required>
                            <span class="form-check-label">Cancel all queued assets on this route.</span>
                        </label>
                        <button type="submit" class="btn btn-outline-danger">Cancel all queued assets</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
