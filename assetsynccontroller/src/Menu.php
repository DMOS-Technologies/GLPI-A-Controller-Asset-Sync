<?php

namespace GlpiPlugin\Assetsynccontroller;

class Menu
{
    public const PAGE_PATH = '/plugins/assetsynccontroller/front/config.php';

    public static function getMenuContent(): array|false
    {
        // A profile needs access to at least one section before the Setup link is useful.
        if (Access::visibleSections() === [])
        {
            return false;
        }

        return [
            'title' => PLUGIN_ASSETSYNCCONTROLLER_NAME,
            'page' => self::PAGE_PATH,
            'icon' => 'ti ti-settings',
        ];
    }

    public static function refreshMenu(array $menu): array
    {
        if (!isset($menu['config']))
        {
            return $menu;
        }

        // GLPI caches the Setup menu between requests. Recheck our entry using the active profile.
        $key = strtolower(self::class);
        $entry = self::getMenuContent();
        if ($entry === false)
        {
            unset($menu['config']['content'][$key]);
            if (($menu['config']['default'] ?? null) === self::PAGE_PATH)
            {
                unset($menu['config']['default']);
                foreach ($menu['config']['content'] ?? [] as $item)
                {
                    if (isset($item['page']))
                    {
                        $menu['config']['default'] = $item['page'];
                        break;
                    }
                }
            }
        }
        else
        {
            $menu['config']['content'][$key] = $entry;
            $menu['config']['default'] ??= self::PAGE_PATH;
        }
        return $menu;
    }
}
