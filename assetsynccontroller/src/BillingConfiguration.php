<?php

namespace GlpiPlugin\Assetsynccontroller;

use Config;
use CronTask;
use RuntimeException;

final class BillingConfiguration
{
    public const FREQUENCIES = ['monthly' => 'Monthly', 'annual' => 'Annual'];

    private const CONFIG_CONTEXT = 'plugin:assetsynccontroller';
    private const CONFIG_KEY = 'hardware_billing';
    private const TASK_NAME = 'AssetBilling';

    public static function load(): array
    {
        $json = Config::getConfigurationValue(self::CONFIG_CONTEXT, self::CONFIG_KEY);
        $saved = $json === null ? [] : json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        // Existing mappings are retained. Workflows run only after an administrator enables them.
        return $saved + [
            'entities' => [], 'fields' => [], 'models' => [], 'swsd_fields' => [],
            'enabled' => [], 'model_labels' => null, 'dropdown_model_labels' => [],
        ];
    }

    public static function save(array $configuration): void
    {
        // Store the selections together so entity, field and model settings update as one record.
        $json = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 65535)
        {
            throw new RuntimeException('The billing configuration is too large to save.');
        }

        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY => $json]);
        if (Config::getConfigurationValue(self::CONFIG_CONTEXT, self::CONFIG_KEY) !== $json)
        {
            throw new RuntimeException('GLPI could not save the hardware billing configuration.');
        }
    }

    public static function registerTask(): bool
    {
        $task = new CronTask();
        // Keep the administrator's automatic-action settings across plugin upgrades.
        if ($task->getFromDBbyName(self::class, self::TASK_NAME))
        {
            return true;
        }

        return (bool) CronTask::register(self::class, self::TASK_NAME, DAY_TIMESTAMP, [
            'state' => CronTask::STATE_WAITING,
            'mode' => CronTask::MODE_EXTERNAL,
            'allowmode' => CronTask::MODE_EXTERNAL,
        ]);
    }

    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY]);
        $task = new CronTask();
        if ($task->getFromDBbyName(self::class, self::TASK_NAME))
        {
            $task->delete(['id' => $task->getID()]);
        }
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => 'Calculate Hardware and SW/SD billing fields for Computers in the configured entities'];
    }

    public static function cronAssetBilling(CronTask $task): int
    {
        // GLPI locks this automatic action before running it.
        return BillingRunner::run($task) > 0 ? 1 : 0;
    }
}
