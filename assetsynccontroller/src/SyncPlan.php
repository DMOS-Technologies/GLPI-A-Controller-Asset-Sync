<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use DBmysql;
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
        return RouteSettings::withLock((int) $route['id'],
            static fn(): array => self::processLocked($agent, $asset, $route, $mappings, $entry, $flags), 3);
    }

    private static function processLocked(
        AgentApi $agent,
        CommonDBTM $asset,
        array $route,
        array $mappings,
        array $entry,
        array $flags,
    ): array
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
        $probe = $agent->syncAsset($identity + [
            'operation' => 'probe',
            'fields' => array_values($fieldsB + $flagFields),
            'include_error_details' => true,
        ]);
        self::checkResponse($probe, $fieldsB + $flagFields);
        if (!is_bool($probe['exists'] ?? null))
        {
            throw new SyncProblem('Agent B returned an invalid serial lookup result.');
        }

        $create = !$probe['exists'];
        $expectedAssetId = $probe['asset_id'] ?? null;
        if (!$create && (!is_int($expectedAssetId) || $expectedAssetId <= 0))
        {
            throw new SyncProblem('Agent B returned an invalid asset ID for this serial.');
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

            if (!array_key_exists('value', $valuesA[$idA] ?? [])
                || (!$create && !array_key_exists('value', $valuesB[$idB] ?? [])))
            {
                throw new SyncProblem('A mapped field is unavailable on one of the GLPI instances.');
            }

            $a = $valuesA[$idA]['value'];
            $b = $create ? null : $valuesB[$idB]['value'];
            $notesA = $valuesA[$idA]['kind'] === 'notes';
            $notesB = SyncNotes::isField($fieldsB[$idB]);
            if ($notesA || $notesB)
            {
                if (!$notesA || !$notesB)
                {
                    throw new SyncProblem('Map Notes to Notes on both GLPI instances.');
                }
                if (!$create && ($valuesB[$idB]['kind'] ?? null) !== 'notes')
                {
                    throw new SyncProblem('Agent B did not return a Notes collection.');
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
                        $direction = self::newerDirection($valuesA[$idA], $valuesB[$idB], $previous[$key] ?? null, 'merge_notes');
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
                    $direction = self::newerDirection($valuesA[$idA], $valuesB[$idB], $previous[$key] ?? null);
                }
                if ($direction === 'a_to_b')
                {
                    $typeB = $valuesB[$idB]['kind'] ?? null;
                    if (!self::matchesDestination($a, $b, $typeB))
                    {
                        $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $b];
                        self::addWrite($writeB, $fieldsB[$idB], $a);
                        $b = $typeB === null ? $a : self::destinationValue($a, $typeB, $fieldsB[$idB], 'b');
                        $writtenB[$key] = $idB;
                    }
                }
                elseif ($direction === 'b_to_a')
                {
                    if (!self::matchesDestination($b, $a, $valuesA[$idA]['kind']))
                    {
                        $expectedB[$idB] = ['field' => $fieldsB[$idB], 'value' => $b];
                        self::addWrite($writeA, $fieldsA[$idA], $b);
                        $a = self::destinationValue($b, $valuesA[$idA]['kind'], $fieldsA[$idA], 'a');
                    }
                }
                elseif ($direction !== null)
                {
                    throw new SyncProblem('A field mapping has an invalid direction.');
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
                    throw new SyncProblem('Agent B did not return a valid Yes/No value for a configured flag.');
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
            $result = $agent->syncAsset($identity + [
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
            self::checkResponse($result, $fieldsB + $flagFields, $writeB + $initialById);
            if (!is_int($result['asset_id'] ?? null) || $result['asset_id'] <= 0
                || (!$create && $result['asset_id'] !== $expectedAssetId))
            {
                throw new SyncProblem('Agent B did not acknowledge the asset write.');
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
                            throw new SyncProblem('GLPI A could not reload the synchronized asset.');
                        }
                        $saved = SyncFields::read($asset, array_column($writeA, 'field'));
                        foreach ($expectedA as $id => $value)
                        {
                            if (!isset($saved[$id]) || self::fingerprint($saved[$id]['value']) !== self::fingerprint($value))
                            {
                                throw new SyncProblem('GLPI A did not retain a mapped field value.');
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
                    throw new SyncProblem('Each GLPI B flag must use its own field outside the ordinary mappings.');
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
            throw new SyncProblem('The route, connection, or source asset changed during sync.');
        }
        $now = SyncFields::read($asset, array_values($fields));
        foreach ($before as $id => $field)
        {
            if (!isset($now[$id]) || self::fingerprint($now[$id]['value']) !== self::fingerprint($field['value']))
            {
                throw new SyncProblem('A mapped field was edited on GLPI A during sync. It will be retried.');
            }
        }
    }

    private static function recheckConfiguration(array $route, string $assetType, array $mappings, array $flags): void
    {
        if (SyncRunner::getConnection($route) === null)
        {
            throw new SyncProblem('The route or connection changed during sync.');
        }
        $routeId = (int) $route['id'];
        if ($mappings !== SyncRunner::getMappings($routeId, $assetType) || $flags !== SyncFlags::get($routeId, $assetType))
        {
            throw new SyncProblem('The field mappings changed during sync.');
        }
    }

    private static function newerDirection(array $a, array $b, ?array $previous, ?string $onTie = null): ?string
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
        if (!is_int($timeA) || !is_int($timeB) || $timeA === $timeB)
        {
            if ($onTie !== null)
            {
                // Notes can merge safely without deleting either version.
                return $onTie;
            }
            throw new SyncProblem('The newest edit could not be established for a bidirectional field.');
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
            throw new SyncProblem('Multiple mappings give different values to the same field.');
        }
        $writes[$id] = ['field' => $field, 'value' => $value];
    }

    private static function destinationValue(mixed $value, string $kind, array $field, string $side): mixed
    {
        try
        {
            return SyncValue::forType($value, $kind);
        }
        catch (SyncException $problem)
        {
            if (in_array($kind, ['dropdown', 'dropdown_list'], true))
            {
                $destination = $side === 'a' ? 'GLPI A' : 'Agent B';
                throw SyncProblem::dropdown("$destination could not convert the attempted dropdown value.", $side, $field, $value);
            }
            throw $problem;
        }
    }

    private static function checkResponse(?array $result, array $fields, array $writes = []): void
    {
        if (($result['ok'] ?? false) === true)
        {
            return;
        }
        $error = $result['error'] ?? null;
        $message = match ($error) {
            'ambiguous_serial' => 'The serial number matches more than one asset in the mapped GLPI B entity.',
            'field_unavailable', 'field_read_only' => 'Agent B could not read or write a mapped field.',
            'reference_unavailable' => 'Agent B could not resolve a mapped dropdown value in the destination entity.',
            'invalid_field_value', 'invalid_reference_value' => 'Agent B rejected a value for the destination field type.',
            'asset_changed', 'asset_identity_changed', 'asset_busy' => 'The asset changed or is busy on Agent B. It will be retried.',
            'entity_not_allowed' => 'The Agent B account cannot access the mapped GLPI B entity.',
            default => 'Agent B did not acknowledge this sync request.',
        };
        // The Agent identifies a failed option. Its name and planned value come from this request.
        $fieldId = $result['field_id'] ?? null;
        if (is_int($fieldId) && in_array($error, ['reference_unavailable', 'invalid_reference_value', 'invalid_field_value'], true))
        {
            $write = $writes[$fieldId] ?? [];
            $field = $write['field'] ?? $fields[$fieldId] ?? null;
            if ($field !== null)
            {
                throw SyncProblem::dropdown($message, 'b', $field, $write['value'] ?? null);
            }
        }
        throw new SyncProblem($message);
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
