<?php

namespace GlpiPlugin\Assetsynccontroller;

use Computer;
use CronTask;
use DateTimeImmutable;
use DateTimeZone;
use DBmysql;
use Entity;
use Throwable;

final class BillingRunner
{
    private const WORKFLOWS = [
        'fields' => [
            'name' => 'Hardware',
            'inputs' => [
                'status' => ['dropdown'],
                'ownership' => ['dropdown'],
                'installed_date' => ['date'],
                'model' => ['dropdown', 'text'],
            ],
            'outputs' => [
                'hw_billable' => ['boolean'],
                'billing_start_date' => ['date'],
                'billing_end_date' => ['date'],
                'frequency' => ['dropdown'],
                'billing_month' => ['number'],
            ],
        ],
        'swsd_fields' => [
            'name' => 'SW/SD',
            'inputs' => [
                'status' => ['dropdown'],
                'redeployed_date' => ['date'],
            ],
            'outputs' => [
                'swsd_billable' => ['boolean'],
                'billing_start_date' => ['date'],
            ],
        ],
    ];

    public static function validate(array $settings, string $section): array
    {
        global $DB;

        $definition = self::WORKFLOWS[$section];
        $savedFields = $settings[$section][Computer::class] ?? [];
        $entities = $settings['entities'];
        if ($entities === [] || count($DB->request([
            'SELECT' => ['id'], 'FROM' => Entity::getTable(), 'WHERE' => ['id' => $entities],
        ])) !== count($entities))
        {
            throw new SyncProblem('Select valid GLPI A billing entities.');
        }

        $plans = [];
        foreach ($entities as $entity)
        {
            $inputs = self::compileFields($savedFields, $definition['inputs'], (int) $entity, false);
            $outputs = self::compileFields($savedFields, $definition['outputs'], (int) $entity, true);
            $addresses = array_map(self::fieldAddress(...), $outputs);
            if (count(array_unique($addresses)) !== count($addresses)
                || array_intersect($addresses, array_map(self::fieldAddress(...), $inputs)) !== [])
            {
                throw new SyncProblem('Map each billing output to its own field, separate from the input fields.');
            }

            $models = [];
            if ($section === 'fields')
            {
                $models = self::validateModels($settings, $inputs['model'], (int) $entity);
                foreach (BillingConfiguration::FREQUENCIES as $label)
                {
                    try
                    {
                        BillingAssetFields::dropdownId($outputs['frequency'], $label, (int) $entity);
                    }
                    catch (SyncException)
                    {
                        throw new SyncProblem('The mapped Frequency dropdown needs unique Monthly and Annual options in every billing entity.');
                    }
                }
            }
            $plans[$entity] = ['inputs' => $inputs, 'outputs' => $outputs, 'models' => $models];
        }
        return $plans;
    }

    private static function compileFields(array $saved, array $slots, int $entity, bool $write): array
    {
        $fields = [];
        foreach ($slots as $slot => $kinds)
        {
            $label = ucfirst(str_replace('_', ' ', $slot));
            if (!isset($saved[$slot]))
            {
                throw new SyncProblem("Map the $label field for Computers.");
            }
            try
            {
                $fields[$slot] = BillingAssetFields::validate(Computer::class, $saved[$slot], $entity, $kinds, $write);
            }
            catch (SyncException)
            {
                throw new SyncProblem("The mapped $label field is unavailable or incompatible in a billing entity.");
            }
        }
        return $fields;
    }

    private static function fieldAddress(array $field): string
    {
        return $field['table'] . '.' . $field['column'];
    }

    private static function validateModels(array $settings, array $field, int $entity): array
    {
        $isDropdown = $field['kind'] === 'dropdown';
        $labels = $settings[$isDropdown ? 'dropdown_model_labels' : 'model_labels'] ?? [];
        if ($labels === [])
        {
            throw new SyncProblem('Assign at least one model to Monthly or Annual.');
        }
        $models = [];
        foreach ($labels as $name => $frequency)
        {
            $label = trim((string) $name);
            if ($label === '' || isset($models[$label]) || !isset(BillingConfiguration::FREQUENCIES[$frequency]))
            {
                throw new SyncProblem('Each model label must be unique across the two frequency groups.');
            }
            if ($isDropdown)
            {
                try
                {
                    BillingAssetFields::dropdownId($field, $label, $entity);
                }
                catch (SyncException)
                {
                    throw new SyncProblem('Each model label must match one dropdown option in every saved billing entity.');
                }
            }
            $models[$label] = BillingConfiguration::FREQUENCIES[$frequency];
        }
        return $models;
    }

    public static function run(CronTask $task): int
    {
        $settings = BillingConfiguration::load();
        $plans = [];
        foreach (self::WORKFLOWS as $section => $definition)
        {
            if (empty($settings['enabled'][$section]))
            {
                continue;
            }
            try
            {
                foreach (self::validate($settings, $section) as $entity => $plan)
                {
                    $plans[$entity][$section] = $plan;
                }
            }
            catch (SyncProblem $problem)
            {
                $task->log($definition['name'] . ' billing skipped. ' . $problem->getMessage());
            }
        }
        if ($plans === [])
        {
            return 0;
        }

        $writeUser = (int) \Config::getConfigurationValue(LocalAccount::CONFIG_CONTEXT, LocalAccount::CONFIG_KEY);
        if (LocalAccount::findActiveUser($writeUser) === null)
        {
            $task->log('Billing skipped. Set an active GLPI A write account under Connections.');
            return 0;
        }

        $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Brunei'));
        $updated = 0;
        $failed = 0;
        foreach ($plans as $entity => $entityPlans)
        {
            if (self::workflowsConflict($entityPlans))
            {
                $task->log("Billing skipped for entity #$entity. Hardware and SW/SD mappings share a field written by one workflow.");
                continue;
            }
            self::runEntity((int) $entity, $entityPlans, $today, $updated, $failed);
        }
        $task->setVolume($updated);
        if ($failed > 0)
        {
            $task->log("$failed computer billing updates failed. Check the write account, field mappings, and GLPI logs.");
        }
        return $updated;
    }

    public static function workflowsConflict(array $plans): bool
    {
        if (!isset($plans['fields'], $plans['swsd_fields']))
        {
            return false;
        }
        $hardware = $plans['fields'];
        $software = $plans['swsd_fields'];
        $hardwareWrites = array_map(self::fieldAddress(...), $hardware['outputs']);
        $softwareWrites = array_map(self::fieldAddress(...), $software['outputs']);
        return array_intersect($hardwareWrites, array_merge($softwareWrites, array_map(self::fieldAddress(...), $software['inputs']))) !== []
            || array_intersect($softwareWrites, array_map(self::fieldAddress(...), $hardware['inputs'])) !== [];
    }

    private static function runEntity(int $entity, array $plans, DateTimeImmutable $today, int &$updated, int &$failed): void
    {
        global $DB;

        $table = Computer::getTable();
        $last = 0;
        // Read a page of IDs at a time so large inventories do not need to fit in memory.
        do
        {
            $computers = $DB->request([
                'SELECT' => ['id'],
                'FROM' => $table,
                'WHERE' => ['entities_id' => $entity, 'is_deleted' => 0, 'is_template' => 0, 'id' => ['>', $last]],
                'ORDER' => ['id'],
                'LIMIT' => 250,
            ]);
            foreach ($computers as $row)
            {
                $last = (int) $row['id'];
                $DB->beginTransaction();
                try
                {
                    $DB->doQuery('SELECT id FROM ' . DBmysql::quoteName($table) . ' WHERE id = ' . $last . ' FOR UPDATE');
                    $asset = new Computer();
                    $changed = $asset->getFromDB($last) && (int) $asset->fields['entities_id'] === $entity
                        && empty($asset->fields['is_deleted']) && empty($asset->fields['is_template'])
                        && LocalAccount::run($asset, static fn(): bool => self::process($asset, $plans, $today));
                    $DB->commit();
                    $updated += (int) $changed;
                }
                catch (Throwable)
                {
                    // Roll back the entire computer if one mapped value cannot be saved.
                    $DB->rollBack();
                    ++$failed;
                }
            }
        }
        while (count($computers) === 250);
    }

    private static function process(Computer $asset, array $plans, DateTimeImmutable $today): bool
    {
        $changes = [];
        foreach ($plans as $section => $plan)
        {
            $values = [];
            foreach ($plan['inputs'] as $slot => $field)
            {
                $values[$slot] = BillingAssetFields::read($asset, $field);
            }
            if ($section === 'fields')
            {
                $label = trim((string) $values['model']);
                $results = BillingRules::hardware($values, $plan['models'][$label] ?? null, $today);
            }
            else
            {
                $results = BillingRules::software($values);
            }
            foreach ($results as $slot => $value)
            {
                $changes[] = ['field' => $plan['outputs'][$slot], 'value' => $value];
            }
        }
        return BillingAssetFields::write($asset, $changes);
    }
}
