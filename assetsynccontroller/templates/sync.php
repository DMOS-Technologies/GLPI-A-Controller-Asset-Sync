<section class="card mt-3" aria-label="Sync Information">
    <div class="card-body">
        <?php if ($routes === []): ?>
            <p>No saved routes are available in your current GLPI scope.</p>
            <?php if (isset($sections['routes'])): ?>
                <a class="btn btn-primary" href="?section=routes">Go to Routes</a>
            <?php endif; ?>
        <?php else: ?>
            <form method="get">
                <input type="hidden" name="section" value="sync-information">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label" for="sync-route">Route</label>
                        <select class="form-select" id="sync-route" name="route_id" required>
                            <option value="">Select a saved route</option>
                            <?php foreach ($routes as $id => $savedRoute): ?>
                                <?php
                                $connectionName = $savedRoute['current_connection_name'] ?? $savedRoute['connection_name'];
                                $entityName = $savedRoute['controller_entity_path'] ?: $savedRoute['controller_entity_name'];
                                ?>
                                <option value="<?= $id ?>"<?= $id === $routeId ? ' selected' : '' ?>>
                                    #<?= $id ?> · <?= htmlescape($connectionName) ?> ·
                                    <?= htmlescape($entityName) ?> → <?= htmlescape($savedRoute['agent_entity_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md">
                        <label class="form-label" for="sync-search">Search</label>
                        <input type="search" class="form-control" id="sync-search" name="sync_search"
                               value="<?= htmlescape($searchText) ?>" placeholder="Asset name or serial number" maxlength="255">
                    </div>
                    <div class="col-md-auto">
                        <label class="form-label" for="sync-asset-type">Asset type</label>
                        <select class="form-select" id="sync-asset-type" name="sync_asset_type">
                            <option value="">All asset types</option>
                            <?php foreach ($assetTypes as $type => $label): ?>
                                <option value="<?= htmlescape($type) ?>"<?= $type === $assetTypeFilter ? ' selected' : '' ?>>
                                    <?= htmlescape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <label class="form-label" for="sync-status">Status</label>
                        <select class="form-select" id="sync-status" name="sync_status">
                            <option value="">All statuses</option>
                            <?php foreach ($syncStatuses as $status => $details): ?>
                                <option value="<?= $status ?>"<?= $status === $statusFilter ? ' selected' : '' ?>>
                                    <?= htmlescape($details['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-auto">
                        <button class="btn btn-primary" type="submit">View assets</button>
                    </div>
                </div>
            </form>
            <?php if ($route !== null): ?>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($mappedAssetTypes !== [] || $canCancelQueuedAssets): ?>
                        <?php require __DIR__ . '/sync-actions.php'; ?>
                    <?php endif; ?>
                    <?php // Use the applied filters without the current page number. ?>
                    <a class="btn btn-outline-secondary" href="?<?= htmlescape(http_build_query($pageQuery + ['sync_export' => 'csv'])) ?>">
                        Export to CSV
                    </a>
                    <a class="btn btn-outline-secondary" href="?<?= htmlescape(http_build_query($pageQuery + ['sync_export' => 'xlsx'])) ?>">
                        Export to XLSX
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($route !== null): ?>
            <section class="border-top mt-3 pt-3" aria-labelledby="sync-route-totals">
                <h2 class="h4 mb-2" id="sync-route-totals">Route totals</h2>
                <dl class="d-flex flex-wrap gap-3 gap-lg-4 mb-2">
                    <div>
                        <dt>Total assets</dt>
                        <dd class="fs-4 fw-bold mb-0"><?= number_format($syncAssetTotal) ?></dd>
                    </div>
                    <div>
                        <dt>Assets left</dt>
                        <dd class="fs-4 fw-bold mb-0"><?= number_format($syncAssetsLeft) ?></dd>
                    </div>
                    <?php foreach ($syncStatuses as $status => $details): ?>
                        <div>
                            <dt class="fw-normal"><?= htmlescape($details['label']) ?></dt>
                            <dd class="mb-0"><span class="badge <?= $details['badge'] ?>"><?= number_format($syncStatusCounts[$status] ?? 0) ?></span></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>
                <p class="text-secondary mb-0">Totals cover the selected route, regardless of filters. Assets left includes failed and cancelled assets.</p>
            </section>
        <?php endif; ?>
    </div>

    <?php if ($route !== null): ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">Asset</th>
                        <th scope="col">Asset type</th>
                        <th scope="col">Serial number</th>
                        <th scope="col">Status</th>
                        <th scope="col" title="Direction of the last successful data transfer">Sync Direction</th>
                        <th scope="col">Last updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($syncRows === []): ?>
                        <tr><td colspan="6">No assets match the current filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($syncRows as $row): ?>
                        <?php $details = $syncStatuses[$row['status']]; ?>
                        <tr>
                            <th scope="row"><?= htmlescape($row['asset_name'] ?: '#' . $row['assets_id']) ?></th>
                            <td><?= htmlescape($assetTypes[$row['asset_type']] ?? $row['asset_type']) ?></td>
                            <td><?= htmlescape($row['serial']) ?></td>
                            <td>
                                <span class="badge <?= $details['badge'] ?>"><?= htmlescape($details['label']) ?></span>
                                <?php if (!empty($row['last_error'])): ?>
                                    <div class="text-secondary mt-1"><?= htmlescape($row['last_error']) ?></div>
                                <?php endif; ?>
                                <?php if ($canRetrySync && $row['status'] === 'failed'): ?>
                                    <?php
                                    // Older failed rows have only a summary and can still be inspected.
                                    $failure = json_decode($row['last_error_details'] ?? '', true);
                                    $failure = is_array($failure) ? $failure : [];
                                    $side = match ($failure['side'] ?? null) {
                                        'a', 'A', 'glpi_a', 'GLPI A' => 'GLPI A',
                                        'b', 'B', 'glpi_b', 'GLPI B' => 'GLPI B',
                                        default => null,
                                    };
                                    $value = $failure['value'] ?? null;
                                    $detailRows = array_filter([
                                        'Destination' => $side,
                                        'Field' => $failure['field'] ?? null,
                                        'GLPI A field' => $failure['field_a'] ?? null,
                                        'GLPI B field' => $failure['field_b'] ?? null,
                                        'Attempted value' => $value === '' ? '(empty)' : $value,
                                        'Reason' => $failure['reason'] ?? null,
                                        'What to do next' => $failure['next_step']
                                            ?? \GlpiPlugin\Assetsynccontroller\SyncProblem::storedGuidance($row['last_error'] ?? ''),
                                    ], static fn($detail): bool => is_string($detail) && $detail !== '');
                                    ?>
                                    <details class="mt-2">
                                        <summary class="btn btn-sm btn-outline-secondary d-inline-flex">See Details</summary>
                                        <div class="border rounded p-2 mt-2">
                                            <p class="mb-2"><?= htmlescape($row['last_error'] ?: 'No error message was recorded.') ?></p>
                                            <?php if ($detailRows !== []): ?>
                                                <dl class="mb-0">
                                                    <?php foreach ($detailRows as $label => $detail): ?>
                                                        <dt><?= htmlescape($label) ?></dt>
                                                        <dd class="text-break"><?= htmlescape($detail) ?></dd>
                                                    <?php endforeach; ?>
                                                </dl>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                    <form method="post" action="<?= htmlescape($syncUrl) ?>" class="mt-2">
                                        <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($retryToken) ?>">
                                        <input type="hidden" name="sync_action" value="retry">
                                        <input type="hidden" name="route_id" value="<?= $routeId ?>">
                                        <input type="hidden" name="sync_id" value="<?= (int) $row['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Retry Sync</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlescape($syncDirections[$row['sync_direction']] ?? '—') ?></td>
                            <td><?= htmlescape(Html::convDateTime($row['date_mod'], null, true) ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($previousPageUrl !== null || $nextPageUrl !== null): ?>
            <nav class="card-footer d-flex gap-2" aria-label="Asset status pages">
                <?php if ($previousPageUrl !== null): ?>
                    <a class="btn btn-outline-secondary" rel="prev" href="<?= htmlescape($previousPageUrl) ?>">Previous</a>
                <?php endif; ?>
                <?php if ($nextPageUrl !== null): ?>
                    <a class="btn btn-outline-secondary" rel="next" href="<?= htmlescape($nextPageUrl) ?>">Next</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
