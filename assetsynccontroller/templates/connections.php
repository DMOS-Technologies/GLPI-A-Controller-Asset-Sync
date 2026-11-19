<?php

// The controller supplies the mode and data. Saved tokens are never sent to this view.
$listUrl = htmlescape($connectionsUrl);
?>

<?php if ($action === '' && $canManageConnections): ?>
    <section class="card mt-3" aria-labelledby="local-account-heading">
        <div class="card-body">
            <h2 class="card-title" id="local-account-heading">Global local account</h2>
            <p class="text-muted mb-3">
                GLPI A uses this account for writes from GLPI B across all connections.
                Grant it access to update the mapped assets and entities.
            </p>
            <form method="post" action="<?= $listUrl ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                <input type="hidden" name="connection_action" value="save_local_write_user">
                <div class="row align-items-end g-2">
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="dropdown_local_write_user_id1">GLPI A user</label>
                        <?php
                        // Use GLPI's searchable user dropdown and keep the empty choice for clearing the setting.
                        User::dropdown([
                            'name' => 'local_write_user_id',
                            'value' => $localWriteUserId,
                            'right' => 'all',
                            'comments' => false,
                            'rand' => 1,
                            'width' => '100%',
                            'emptylabel' => 'No account selected',
                        ]);
                        ?>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">Save local account</button>
                    </div>
                </div>
            </form>
        </div>
    </section>
<?php endif; ?>

<section class="card mt-3" aria-label="Connections">
    <?php if ($action === ''): ?>
        <?php if ($canManageConnections): ?>
            <div class="card-header">
                <a class="btn btn-primary" href="<?= $listUrl ?>&amp;connection_action=add">
                    <i class="ti ti-plus" aria-hidden="true"></i> Add connection
                </a>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th scope="col">Connection name</th>
                        <th scope="col">Sync status</th>
                        <th scope="col">Last contact</th>
                        <?php if ($canManageConnections): ?>
                            <th scope="col">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($connections) === 0): ?>
                        <tr><td colspan="<?= $canManageConnections ? 4 : 3 ?>">No connections yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($connections as $row): ?>
                        <?php
                        $id = (int) $row['id'];
                        $isEnabled = (bool) $row['is_enabled'];
                        $statusAction = $isEnabled ? 'disable' : 'enable';
                        $statusLabel = $isEnabled ? 'Disable' : 'Enable';
                        ?>
                        <tr>
                            <th scope="row"><?= htmlescape($row['name']) ?></th>
                            <td>
                                <span class="badge <?= $isEnabled ? 'bg-green-lt' : 'bg-secondary-lt' ?>">
                                    <?= $isEnabled ? 'Enabled' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <?= $row['last_contact'] === null
                                    ? 'Never'
                                    : htmlescape(Html::convDateTime($row['last_contact'], null, true)) ?>
                            </td>
                            <?php if ($canManageConnections): ?>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-secondary"
                                           href="<?= $listUrl ?>&amp;connection_action=edit&amp;id=<?= $id ?>">Edit</a>
                                        <form method="post" action="<?= $listUrl ?>">
                                            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                                            <input type="hidden" name="id" value="<?= $id ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"
                                                    name="connection_action" value="test">Test connection</button>
                                            <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                    name="connection_action" value="<?= $statusAction ?>"><?= $statusLabel ?></button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-danger"
                                           href="<?= $listUrl ?>&amp;connection_action=delete&amp;id=<?= $id ?>">Delete</a>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?php
        $isAdding = $action === 'add';
        $isDeleting = $action === 'delete';
        $formTitle = $isAdding ? 'Add connection' : ($isDeleting ? 'Delete connection' : 'Edit connection');
        ?>
        <div class="card-body">
            <h2 class="card-title"><?= $formTitle ?></h2>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlescape($error) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= $listUrl ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlescape($csrfToken) ?>">
                <input type="hidden" name="connection_action" value="<?= htmlescape($action) ?>">
                <?php if (!$isAdding): ?>
                    <input type="hidden" name="id" value="<?= (int) $connection['id'] ?>">
                <?php endif; ?>

                <?php if ($isDeleting): ?>
                    <p>Delete <strong><?= htmlescape($connection['name']) ?></strong> and its saved credentials?</p>
                    <p>This also removes its routes, field mappings, and sync status records.</p>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label" for="connection-name">Connection name</label>
                        <input class="form-control" type="text" id="connection-name" name="name" maxlength="255"
                               value="<?= htmlescape($connection['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="connection-api-url">Agent B REST API URL</label>
                        <input class="form-control" type="url" id="connection-api-url" name="api_url" maxlength="2048"
                               placeholder="https://glpi-b.example/apirest.php"
                               value="<?= htmlescape($connection['api_url']) ?>" required>
                    </div>
                    <?php if (!$isAdding): ?>
                        <p class="text-muted">Leave either token blank to keep its saved value.</p>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="connection-application-token">Application Token</label>
                        <input class="form-control" type="password" id="connection-application-token" name="application_token"
                               maxlength="1024" autocomplete="new-password"<?= $isAdding ? ' required' : '' ?>>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="connection-user-token">User Token</label>
                        <input class="form-control" type="password" id="connection-user-token" name="user_token"
                               maxlength="1024" autocomplete="new-password"<?= $isAdding ? ' required' : '' ?>>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn <?= $isDeleting ? 'btn-danger' : 'btn-primary' ?>">
                    <?= $isDeleting ? 'Delete connection' : ($isAdding ? 'Add connection' : 'Save changes') ?>
                </button>
                <a class="btn btn-outline-secondary" href="<?= $listUrl ?>">Cancel</a>
            </form>
        </div>
    <?php endif; ?>
</section>
