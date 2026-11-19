<?php

namespace GlpiPlugin\Assetsynccontroller;

use CronTask;

class SyncTask
{
    private const NAME = 'AssetSync';

    public static function register(): bool
    {
        $task = new CronTask();
        // Keep the administrator's automatic-action settings across plugin upgrades.
        if ($task->getFromDBbyName(self::class, self::NAME))
        {
            return true;
        }

        // The official GLPI Docker worker already checks CLI tasks every minute.
        return (bool) CronTask::register(self::class, self::NAME, MINUTE_TIMESTAMP, [
            'state' => CronTask::STATE_WAITING,
            'mode' => CronTask::MODE_EXTERNAL,
            'allowmode' => CronTask::MODE_EXTERNAL,
        ]);
    }

    public static function cronInfo(string $name): array
    {
        return ['description' => 'Sync assets on enabled Asset Sync Controller routes'];
    }

    public static function cronAssetSync(CronTask $task): int
    {
        // GLPI locks the automatic action before calling this method.
        $processedAssets = SyncRunner::run();
        $task->setVolume($processedAssets);
        return $processedAssets > 0 ? 1 : 0;
    }
}
