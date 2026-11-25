<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use DBmysql;
use Generator;
use Throwable;

class SyncPlan
{
    public static function process(
        AgentApi $agent,
        CommonDBTM $asset,
        array $route,
        array $mappings,
        array $entry,
        array $flags = [],
    ): array
    {
        // Route and mapping changes share this lock with dispatch, including the writes on both GLPI instances.
        return RouteSettings::withLock((int) $route['id'], static function () use (
            $agent, $asset, $route, $mappings, $entry, $flags
        ): array
        {
            $steps = self::steps($asset, $route, $mappings, $entry, $flags);
            while ($steps->valid())
            {
                $steps->send($agent->syncAsset($steps->current()));
            }
            return $steps->getReturn();
        }, 3);
    }

    /**
     * Yield each Agent B request and accept its individual response from the transport.
     * The caller holds the route lock until this asset plan finishes.
     *
     * @return Generator<int, array, ?array, array>
     */
    public static function steps(
        CommonDBTM $asset,
        array $route,
        array $mappings,
        array $entry,
        array $flags = [],
    ): Generator
    {
        $routeMappings = $mappings;
        $mappings = SyncChanges::selectMappings($mappings, $entry['pending_fields'] ?? null);
        $previous = json_decode($entry['field_snapshot'] ?? '[]', true) ?? [];
        if ($mappings === [])
        {
            // A mapping can be removed after an edit is queued.
            return ['snapshot' => $previous, 'direction' => null];
        }

        [$fieldsA, $fieldsB] = self::descriptors($mappings);
        $flagFields = self::flagFields($flags, $routeMappings);
        $valuesA = SyncFields::read($asset, array_values($fieldsA));
        $identity = [
            'itemtype' => $entry['asset_type'],
            'entity_id' => (int) $route['agent_entity_id'],
            'serial' => $entry['serial'],
        ];
        self::recheckConfiguration($route, $entry['asset_type'], $routeMappings, $flags);
        $probe = yield ($identity + [
            'operation' => 'probe',
            'fields' => array_values($fieldsB + $flagFields),
            'include_error_details' => true,
        ]);
        self::checkResponse($probe, $fieldsB + $flagFields);
        if (!is_bool($probe['exists'] ?? null))
        {
            throw new SyncProblem('Agent B returned an invalid serial lookup result.', details: [
                'next_step' => 'Check the Agent B plugin version and its logs, then retry.',
            ]);
        }

        $create = !$probe['exists'];
        $expectedAssetId = $probe['asset_id'] ?? null;
        if (!$create && (!is_int($expectedAssetId) || $expectedAssetId <= 0))
        {
            throw new SyncProblem('Agent B found the serial but returned an invalid asset ID.', details: [
                'next_step' => 'Check that the serial identifies one accessible asset in the mapped GLPI B entity, then retry.',
            ]);
        }
        if ($create && $mappings !== $routeMappings)
        {
            // A missing destination still needs a complete initial copy of this one asset.
            $mappings = $routeMappings;
            [$fieldsA, $fieldsB] = self::descriptors($mappings);
            $valuesA = SyncFields::read($asset, array_values($fieldsA));
        }
        $valuesB = $probe['values'] ?? [];
        $writeA = [];
        $writeB = [];
        // Keep source descriptors locally so a remapped credential is still hidden in a failure.
        $sourcesForB = [];
        $expectedB = [];
        $writtenB = [];
        // Keep the comparison baseline for mappings that were not part of this run.
        $snapshot = $create ? [] : $previous;

        foreach ($mappings as $mapping)
        {
            $idA = (int) $mapping['controller_option_id'];
            $idB = (int) $mapping['agent_option_id'];
            $direction = $mapping['direction'];
            $key = $idA . ':' . $idB . ':' . $direction;
            $sourcesForB[$idB][] = $fieldsA[$idA];

            if (!array_key_exists('value', $valuesA[$idA] ?? [])
                || (!$create && !array_key_exists('value', $valuesB[$idB] ?? [])))
            {
                $side = !array_key_exists('value', $valuesA[$idA] ?? []) ? 'a' : 'b';
                throw new SyncProblem('One GLPI instance could not return a mapped field value.', details: [
                    'side' => $side,
                    'field' => self::fieldLabel($side === 'a' ? $fieldsA[$idA] : $fieldsB[$idB]),
                    'next_step' => 'Reload the field list in Mappings and check the field and asset permissions on that instance.',
                ]);
            }

            $a = $valuesA[$idA]['value'];
            $b = $create ? null : $valuesB[$idB]['value'];
            $notesA = $valuesA[$idA]['kind'] === 'notes';
            $notesB = SyncNotes::isField($fieldsB[$idB]);
            if ($notesA || $notesB)
            {
                if (!$notesA || !$notesB)
                {
                    throw new SyncProblem('The Notes field is mapped to a different field type.', details: [
                        'field_a' => self::fieldLabel($fieldsA[$idA]),
                        'field_b' => self::fieldLabel($fieldsB[$idB]),
                        'next_step' => 'Select Notes on both sides of the mapping, then retry.',
                    ]);
                }
                if (!$create && ($valuesB[$idB]['kind'] ?? null) !== 'notes')
                {
                    throw new SyncProblem('Agent B did not return a Notes collection.', details: [
                        'field_b' => self::fieldLabel($fieldsB[$idB]),
                        'next_step' => 'Check the Notes field mapping and the Agent B account permissions.',
                    ]);
                }

                // Notes are separate records. Copy missing bodies in the selected direction and keep local notes.
                $b = $create ? [] : SyncNotes::normalize($b);
                if ($create)
                {
                    $direction = 'a_to_b';
                }
                elseif ($direction === 'newer_edit' && $a !== $b)
                {
                    if ($a === [] || $b === [])
                    {
                        $direction = $a === [] ? 'b_to_a' : 'a_to_b';
                    }
                    else
                    {
                        $direction = self::newerDirection($valuesA[$idA], $valuesB[$idB], $previous[$key] ?? null, $fieldsA[$idA], $fieldsB[$idB], 'merge_notes');
                    }
                }

                $merged = SyncNotes::normalize(array_merge($a, $b));
                $notesBeforeB = $b;
                if (in_array($direction, ['a_to_b', 'merge_notes'], true) && $merged !== $b)
                {
                    if (!$create)
                    {
                        $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $b];
                    }
                    self::addWrite($writeB, $fieldsB[$idB], $a);
                    $b = $merged;
                }
                if (in_array($direction, ['b_to_a', 'merge_notes'], true) && $merged !== $a)
                {
                    $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $notesBeforeB];
                    self::addWrite($writeA, $fieldsA[$idA], $notesBeforeB);
                    $a = $merged;
                }
                $snapshot[$key] = ['a' => self::fingerprint($a), 'b' => self::fingerprint($b)];
                continue;
            }

            if ($create)
            {
                // New B assets are seeded from A. Ownership rules apply once both records exist.
                if ($a !== null && $a !== '' && $a !== [])
                {
                    self::addWrite($writeB, $fieldsB[$idB], $a);
                    $b = $a;
                    $writtenB[$key] = $idB;
                }
            }
            elseif (self::fingerprint($a) !== self::fingerprint($b)
                && !self::matchesSnapshot($a, $b, $previous[$key] ?? null))
            {
                if ($direction === 'newer_edit')
                {
                    $direction = self::newerDirection($valuesA[$idA], $valuesB[$idB], $previous[$key] ?? null, $fieldsA[$idA], $fieldsB[$idB]);
                }
                if ($direction === 'a_to_b')
                {
                    $typeB = $valuesB[$idB]['kind'] ?? null;
                    if (!self::matchesDestination($a, $b, $typeB))
                    {
                        $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $b];
                        self::addWrite($writeB, $fieldsB[$idB], $a);
                        $b = $typeB === null ? $a : self::destinationValue($a, $typeB, $fieldsB[$idB], 'b', $fieldsA[$idA]);
                        $writtenB[$key] = $idB;
                    }
                }
                elseif ($direction === 'b_to_a')
                {
                    if (!self::matchesDestination($b, $a, $valuesA[$idA]['kind']))
                    {
                        $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $b];
                        self::addWrite($writeA, $fieldsA[$idA], $b);
                        $a = self::destinationValue($b, $valuesA[$idA]['kind'], $fieldsA[$idA], 'a', $fieldsB[$idB]);
                    }
                }
                elseif ($direction !== null)
                {
                    throw new SyncProblem('A field mapping has an invalid direction.', details: [
                        'field_a' => self::fieldLabel($fieldsA[$idA]),
                        'field_b' => self::fieldLabel($fieldsB[$idB]),
                        'next_step' => 'Open Mappings, choose a valid sync direction for this field pair, and retry.',
                    ]);
                }
            }
            $snapshot[$key] = ['a' => self::fingerprint($a), 'b' => self::fingerprint($b)];
        }

        foreach ($flagFields as $id => $field)
        {
            if (!$create)
            {
                $current = $valuesB[$id] ?? [];
                if (($current['kind'] ?? null) !== 'boolean' || !array_key_exists('value', $current)
                    || ($current['value'] !== null && !is_bool($current['value'])))
                {
                    throw new SyncProblem('Agent B did not return a valid Yes/No value for a configured flag.', details: [
                        'field_b' => self::fieldLabel($field),
                        'next_step' => 'Check that the selected flag is still an accessible Yes/No field on GLPI B.',
                    ]);
                }
                if ($current['value'] === true)
                {
                    continue;
                }
                $expectedB[$id] = ['field' => $field, 'value' => $current['value']];
            }

            // Flags have no GLPI A value and apply regardless of the ordinary mapping directions.
            self::addWrite($writeB, $field, true);
        }

        if ($create || $writeA !== [] || $writeB !== [])
        {
            self::recheckSource($asset, $route, $entry, $routeMappings, $fieldsA, $valuesA, $flags);
            if ($writeA !== [] && $writeB !== [])
            {
                // Check the local account before a mixed sync can commit any changes on B.
                LocalAccount::run($asset, static fn() => null);
            }
            $initialNative = [];
            if ($create)
            {
                foreach ($asset->fields as $column => $value)
                {
                    if (SyncFields::nativeColumnCanWrite($column) && is_scalar($value) && $value !== '')
                    {
                        $initialNative[$column] = $value;
                    }
                }
            }

            // An explicit mapping already handles this source reference, even if the B option ID differs.
            $initial = $create ? SyncFields::populatedNativeReferences($asset, $fieldsA + $writeB) : [];
            $result = yield ($identity + [
                'operation' => 'apply',
                'create' => $create,
                'expected_asset_id' => $expectedAssetId,
                'initial_native' => $initialNative,
                'initial' => $initial,
                'values' => array_values($writeB),
                'expected' => array_values($expectedB),
                'include_error_details' => true,
            ]);
            $initialById = [];
            foreach ($initial as $entryToCopy)
            {
                $initialById[$entryToCopy['field']['id']] = $entryToCopy;
            }
            // A response can name a pending write or a field read for the update's precondition.
            $expectedFields = array_map(static fn(array $entry): array => $entry['field'], $expectedB);
            self::checkResponse($result, $expectedFields, $writeB + $initialById, $initialNative, $sourcesForB);
            if (!is_int($result['asset_id'] ?? null) || $result['asset_id'] <= 0
                || (!$create && $result['asset_id'] !== $expectedAssetId))
            {
                throw new SyncProblem('Agent B did not confirm the write to the expected asset.', details: [
                    'next_step' => 'Check the current asset record and sync logs on GLPI B, then retry.',
                ]);
            }

            // B can store a full dropdown path after matching a shorter label. Use its actual stored value.
            $appliedValues = $result['applied_values'] ?? [];
            if (is_array($appliedValues))
            {
                foreach ($writtenB as $key => $idB)
                {
                    if (array_key_exists($idB, $appliedValues))
                    {
                        $snapshot[$key]['b'] = self::fingerprint($appliedValues[$idB]);
                    }
                }
            }

            if ($writeA !== [])
            {
                global $DB;
                $DB->beginTransaction();
                try
                {
                    // Check the expected A values while holding the asset row for this update.
                    $DB->doQuery('SELECT id FROM ' . DBmysql::quoteName($asset::getTable())
                        . ' WHERE id = ' . (int) $entry['assets_id'] . ' FOR UPDATE');
                    self::recheckSource($asset, $route, $entry, $routeMappings, $fieldsA, $valuesA, $flags);
                    $saved = LocalAccount::run($asset, static function () use ($asset, $writeA, $entry): array
                    {
                        $expectedA = SyncFields::write($asset, array_values($writeA));
                        if (!$asset->getFromDB($entry['assets_id']))
                        {
                            throw new SyncProblem('GLPI A could not reload the asset after the attempted write.', details: [
                                'next_step' => 'Check that the asset still exists and is accessible in the mapped GLPI A entity.',
                            ]);
                        }
                        $saved = SyncFields::read($asset, array_column($writeA, 'field'));
                        foreach ($expectedA as $id => $value)
                        {
                            if (!isset($saved[$id]) || self::fingerprint($saved[$id]['value']) !== self::fingerprint($value))
                            {
                                throw new SyncProblem('GLPI A did not retain a mapped field value.', details: [
                                    'field_a' => self::fieldLabel($writeA[$id]['field']),
                                    'next_step' => 'Check the field restrictions and asset rules on GLPI A before retrying.',
                                ]);
                            }
                        }
                        return $saved;
                    });
                    foreach ($mappings as $mapping)
                    {
                        $idA = (int) $mapping['controller_option_id'];
                        if (isset($saved[$idA]))
                        {
                            $key = $idA . ':' . $mapping['agent_option_id'] . ':' . $mapping['direction'];
                            $snapshot[$key]['a'] = self::fingerprint($saved[$idA]['value']);
                        }
                    }
                    $DB->commit();
                }
                catch (Throwable $error)
                {
                    $DB->rollBack();
                    throw $error;
                }
            }
        }
        // A successful probe alone does not move data.
        $wroteA = $writeA !== [];
        $wroteB = $create || $writeB !== [];
        $direction = match (true) {
            $wroteA && $wroteB => 'both',
            $wroteA => 'b_to_a',
            $wroteB => 'a_to_b',
            default => null,
        };

        return ['snapshot' => $snapshot, 'direction' => $direction];
    }

    private static function descriptors(array $mappings): array
    {
        $fieldsA = [];
        $fieldsB = [];
        foreach ($mappings as $mapping)
        {
            $idA = (int) $mapping['controller_option_id'];
            $idB = (int) $mapping['agent_option_id'];
            $fieldsA[$idA] = ['id' => $idA] + json_decode($mapping['controller_field'], true, flags: JSON_THROW_ON_ERROR);
            $fieldsB[$idB] = ['id' => $idB] + json_decode($mapping['agent_field'], true, flags: JSON_THROW_ON_ERROR);
        }
        return [$fieldsA, $fieldsB];
    }

    private static function flagFields(array $flags, array $mappings): array
    {
        if ($flags === [])
        {
            return [];
        }

        // Check all ordinary mappings, including the ones outside this asset's pending changes.
        [, $mappedFields] = self::descriptors($mappings);
        $fields = [];
        foreach ($flags as $field)
        {
            foreach ($mappedFields + $fields as $other)
            {
                if (SyncFlags::sameField($field, $other))
                {
                    throw new SyncProblem('A GLPI B flag points to a field already used by another mapping or flag.', details: [
                        'field_b' => self::fieldLabel($field),
                        'next_step' => 'Choose a separate destination field for the flag in Mappings, then retry.',
                    ]);
                }
            }
            $fields[$field['id']] = $field;
        }
        return $fields;
    }

    private static function recheckSource(
        CommonDBTM $asset,
        array $route,
        array $entry,
        array $mappings,
        array $fields,
        array $before,
        array $flags,
    ): void
    {
        self::recheckConfiguration($route, $entry['asset_type'], $mappings, $flags);
        if (!$asset->getFromDB($entry['assets_id'])
            || (int) $asset->fields['entities_id'] !== (int) $route['controller_entity_id']
            || !empty($asset->fields['is_deleted']) || !empty($asset->fields['is_template'])
            || trim((string) $asset->fields['serial']) !== $entry['serial'])
        {
            throw new SyncProblem('The source asset changed or left the mapped GLPI A entity during sync.', details: [
                'next_step' => 'Verify the asset still exists in the mapped entity with the expected serial number, then retry.',
            ]);
        }
        $now = SyncFields::read($asset, array_values($fields));
        foreach ($before as $id => $field)
        {
            if (!isset($now[$id]) || self::fingerprint($now[$id]['value']) !== self::fingerprint($field['value']))
            {
                throw new SyncProblem('A mapped field was edited on GLPI A during sync.', details: [
                    'field_a' => self::fieldLabel($fields[$id]),
                    'next_step' => 'Retry once edits to the asset are complete.',
                ]);
            }
        }
    }

    private static function recheckConfiguration(array $route, string $assetType, array $mappings, array $flags): void
    {
        if (SyncRunner::getConnection($route) === null)
        {
            throw new SyncProblem('The route or connection changed during sync.', details: [
                'next_step' => 'Check that the route and connection are enabled and that the route is verified before retrying.',
            ]);
        }
        $routeId = (int) $route['id'];
        if ($mappings !== SyncRunner::getMappings($routeId, $assetType) || $flags !== SyncFlags::get($routeId, $assetType))
        {
            throw new SyncProblem('The field mappings or flags changed during sync.', details: [
                'next_step' => 'Review the saved mappings and flags before retrying.',
            ]);
        }
    }

    private static function newerDirection(
        array $a,
        array $b,
        ?array $previous,
        array $fieldA,
        array $fieldB,
        ?string $onTie = null,
    ): ?string
    {
        // A one-sided edit after the last acknowledgement is newer even without field history.
        if ($previous !== null)
        {
            $changedA = self::fingerprint($a['value']) !== $previous['a'];
            $changedB = self::fingerprint($b['value']) !== $previous['b'];
            if ($changedA !== $changedB)
            {
                return $changedA ? 'a_to_b' : 'b_to_a';
            }
            if (!$changedA)
            {
                return null;
            }
        }
        elseif (self::matchesDestination($a['value'], $b['value'], $b['kind'] ?? null)
            && self::matchesDestination($b['value'], $a['value'], $a['kind']))
        {
            return null;
        }

        $timeA = $a['modified_at'] ?? null;
        $timeB = $b['modified_at'] ?? null;
        $hasHistoryA = is_int($timeA);
        $hasHistoryB = is_int($timeB);

        // When only one side has a recorded field edit, use that side's value.
        if ($hasHistoryA !== $hasHistoryB)
        {
            return $hasHistoryA ? 'a_to_b' : 'b_to_a';
        }

        $bothMissing = !$hasHistoryA && !$hasHistoryB;
        if ($bothMissing || $timeA === $timeB)
        {
            if ($onTie !== null)
            {
                // Notes can merge safely without deleting either version.
                return $onTie;
            }
            // Do not guess which value should win when history cannot establish the order.
            throw new SyncProblem(
                $bothMissing ? 'Two-way sync conflict. A field edit time is missing on GLPI A and GLPI B.'
                    : 'Two-way sync conflict. Both edits have the same recorded time.',
                details: [
                    'field_a' => self::fieldLabel($fieldA),
                    'field_b' => self::fieldLabel($fieldB),
                    'reason' => $bothMissing
                        ? 'The mapped values differ, but neither side has a usable field edit time in History. Newer Edit Wins cannot choose which value to keep.'
                        : 'The mapped values differ, but both fields have the same edit time in History. Newer Edit Wins cannot choose which value to keep.',
                    'next_step' => 'Make a new edit on the side whose value should win and retry. To keep that side authoritative, set a one-way direction in Mappings.',
                ],
            );
        }
        return $timeA > $timeB ? 'a_to_b' : 'b_to_a';
    }

    private static function matchesDestination(mixed $source, mixed $current, ?string $kind): bool
    {
        if ($kind !== null && $kind !== 'item')
        {
            try
            {
                $source = SyncValue::forType($source, $kind);
            }
            catch (SyncException)
            {
                return false;
            }
        }
        return self::fingerprint($source) === self::fingerprint($current);
    }

    private static function matchesSnapshot(mixed $a, mixed $b, ?array $previous): bool
    {
        // Matching labels can look different on the two systems, such as a short name and a full path.
        return $previous !== null && self::fingerprint($a) === $previous['a']
            && self::fingerprint($b) === $previous['b'];
    }

    private static function addWrite(array &$writes, array $field, mixed $value): void
    {
        $id = $field['id'];
        if (isset($writes[$id]) && self::fingerprint($writes[$id]['value']) !== self::fingerprint($value))
        {
            throw new SyncProblem('Multiple mappings give different values to the same destination field.', details: [
                'field' => self::fieldLabel($field),
                'next_step' => 'Review the mappings for this field and keep a single source before retrying.',
            ]);
        }
        $writes[$id] = ['field' => $field, 'value' => $value];
    }

    private static function destinationValue(mixed $value, string $kind, array $field, string $side, array $source): mixed
    {
        try
        {
            return SyncValue::forType($value, $kind);
        }
        catch (SyncException)
        {
            $destination = $side === 'a' ? 'GLPI A' : 'GLPI B';
            $type = match ($kind) {
                'boolean' => 'a Yes/No value',
                'date' => 'a valid date',
                'datetime' => 'a valid date and time',
                'dropdown' => 'a single dropdown option',
                'dropdown_list' => 'a list of dropdown options',
                'integer' => 'a whole number',
                'number' => 'a number',
                'text' => 'text',
                default => 'a value of its configured type',
            };
            $message = in_array($kind, ['dropdown', 'dropdown_list'], true)
                ? "$destination could not convert the attempted dropdown value."
                : "$destination cannot use this value for the mapped field type.";
            $reason = "The destination expects $type. Controller A could not convert the value.";
            if ($side === 'b')
            {
                $reason .= ' The sync stopped before sending the value to GLPI B.';
            }
            throw new SyncProblem($message, details: [
                'field' => self::fieldLabel($field, $side === 'b' ? ($field['id'] ?? null) : null),
                'side' => $side,
                'value' => SyncProblem::attemptedValue($field, $value, [$source]),
                'reason' => $reason,
                'next_step' => 'Check the mapped source value and the expected field type on the destination.',
            ]);
        }
    }

    private static function checkResponse(
        ?array $result,
        array $fields,
        array $writes = [],
        array $initialNative = [],
        array $sources = [],
    ): void
    {
        if (($result['ok'] ?? false) === true)
        {
            return;
        }
        $error = $result['error'] ?? null;
        [$message, $nextStep] = match ($error) {
            'ambiguous_serial' => ['Multiple assets on GLPI B have this serial number in the mapped entity.', 'Find the duplicate serials for this asset type in the destination entity and correct them before retrying.'],
            'asset_access_denied' => ['The Agent B account is missing permission to read, create, or edit the asset.', 'Check the account profile and its asset permissions for the mapped GLPI B entity.'],
            'asset_busy' => ['Another request is processing this asset on GLPI B.', 'Wait for that request to finish, then retry.'],
            'asset_changed' => ['The asset changed on GLPI B during sync.', 'Check the latest values on both sides and retry once the asset is no longer being edited.'],
            'asset_identity_changed' => ['The asset on GLPI B no longer matches the serial number, entity, or record found at lookup.', 'Check the asset serial number and mapped entity on GLPI B, then retry.'],
            'asset_type_unavailable' => ['The mapped asset type is unavailable to Agent B.', 'Check that the asset type exists and the Agent B account has access to it. Reload the route and its mappings if the asset type changed.'],
            'authentication_failed', 'authentication_required' => ['Agent B could not authenticate the sync request.', 'Verify the Agent B URL and saved application and user tokens in Connections.'],
            'entity_not_allowed' => ['The Agent B account cannot access the mapped GLPI B entity.', 'Check the destination entity in Routes and grant the Agent B account access to it.'],
            'field_read_only' => ['A mapped field on GLPI B is read-only for the Agent B account.', 'Check the field settings and account permissions, or select a writable destination in Mappings.'],
            'field_unavailable' => ['Agent B cannot find or read a mapped field.', 'Reload the available fields in Mappings and check that the field still exists and is visible to the Agent B account.'],
            'field_write_failed' => ['GLPI B could not save a mapped field.', 'Check the destination field settings, including any required fields. If retry still fails, check the GLPI B logs.'],
            'invalid_field_value' => ['GLPI B rejected a value for a mapped field.', 'Check the required format and allowed values for the destination field.'],
            'invalid_reference_value' => ['GLPI B rejected the format of a mapped reference value.', 'Check whether the destination expects a single dropdown option, multiple options, or a linked record.'],
            'notes_write_failed' => ['GLPI B could not save the copied Notes.', 'Check the Agent B account’s Notes permissions for this asset and review the GLPI B logs.'],
            'reference_unavailable' => ['GLPI B could not find a unique match for a mapped dropdown option or linked record.', 'Check that the matching option or record exists and is visible in the destination entity. Resolve any duplicate matches before retrying.'],
            'unmapped_field_changed' => ['A GLPI B rule or plugin changed a field outside this mapping. The write was rolled back.', 'Review the asset rules or plugin settings on GLPI B before retrying.'],
            'batch_payload_too_large', 'request_too_large' => ['The mapped data exceeds the Agent B request limit.', 'Check for unusually large mapped values, such as long notes, and reduce the data included in the sync.'],
            'invalid_request', 'method_not_allowed', 'unsupported_media_type' => ['Agent B rejected the sync request format.', 'Verify that both plugins are on compatible versions and that the connection points to the correct GLPI B URL.'],
            'internal_error' => ['Agent B encountered an internal error while processing the asset.', 'Check the GLPI B server logs around the failure time before retrying.'],
            default => ['Agent B did not return a usable response for this asset.', 'Check the connection and Agent B availability. If it keeps failing, check the GLPI B logs and plugin versions.'],
        };
        $details = ['next_step' => $nextStep];
        if (in_array($error, [
            'reference_unavailable', 'invalid_reference_value', 'invalid_field_value',
            'field_read_only', 'field_unavailable', 'field_write_failed', 'notes_write_failed',
        ], true))
        {
            $details += self::agentFailureDetails($result, $fields, $writes, $initialNative, $sources);
        }
        if (isset($details['group']))
        {
            $details['next_step'] = 'Review the group’s required fields and allowed formats on GLPI B.';
        }
        throw new SyncProblem($message, details: $details);
    }

    private static function agentFailureDetails(array $result, array $fields, array $writes, array $initialNative, array $sources): array
    {
        $details = [];
        $reason = self::agentLabel($result['reason'] ?? null);
        if ($reason !== null)
        {
            $details['reason'] = $reason;
        }

        // Names and values for mapped fields come from the request, never from the Agent's response.
        $fieldId = $result['field_id'] ?? null;
        $field = is_int($fieldId) && $fieldId > 0 ? ($writes[$fieldId]['field'] ?? $fields[$fieldId] ?? null) : null;
        $column = $result['column'] ?? null;
        if ($field !== null)
        {
            $details['field'] = self::fieldLabel($field, $fieldId);
            if (isset($writes[$fieldId]))
            {
                $details['value'] = SyncProblem::attemptedValue($field, $writes[$fieldId]['value'], $sources[$fieldId] ?? []);
            }
        }
        elseif (is_string($column) && array_key_exists($column, $initialNative))
        {
            $details['field'] = self::columnLabel($column);
            $field = ['field' => $column, 'name' => $details['field']];
            $details['value'] = SyncProblem::attemptedValue($field, $initialNative[$column]);
        }

        $group = self::agentLabel($result['group'] ?? null);
        if ($group !== null)
        {
            $details['group'] = $group;
            $labels = [];
            $fieldIds = $result['field_ids'] ?? [];
            $columns = $result['columns'] ?? [];
            foreach (is_array($fieldIds) && array_is_list($fieldIds) ? $fieldIds : [] as $id)
            {
                if (is_int($id) && $id > 0 && isset($writes[$id]))
                {
                    $labels[] = self::fieldLabel($writes[$id]['field'], $id);
                }
            }
            foreach (is_array($columns) && array_is_list($columns) ? $columns : [] as $seedColumn)
            {
                if (is_string($seedColumn) && array_key_exists($seedColumn, $initialNative))
                {
                    $labels[] = self::columnLabel($seedColumn);
                }
            }
            $details['fields'] = array_values(array_unique($labels));
        }
        return $details === [] ? [] : ['side' => 'b'] + $details;
    }

    private static function agentLabel(mixed $label): ?string
    {
        if (!is_string($label))
        {
            return null;
        }
        $label = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $label));
        return $label === '' ? null : mb_substr($label, 0, 500);
    }

    private static function columnLabel(string $column): string
    {
        return match ($column) {
            'otherserial' => 'Inventory/Asset Number',
            default => ucwords(str_replace('_', ' ', $column)),
        };
    }

    private static function fieldLabel(array $field, ?int $id = null): string
    {
        $label = mb_substr((string) ($field['name'] ?? $field['field'] ?? 'Unknown field'), 0, 500);
        return $id === null ? $label : "$label (#$id)";
    }

    private static function fingerprint(mixed $value): string
    {
        if ($value === '' || $value === null)
        {
            $value = null;
        }
        elseif (is_scalar($value))
        {
            // GLPI may expose the same scalar as a PHP number on one side and text on the other.
            $value = is_bool($value) ? (string) (int) $value : (string) $value;
        }
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
