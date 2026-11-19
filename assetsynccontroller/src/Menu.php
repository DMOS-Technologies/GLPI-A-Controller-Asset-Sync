<?php

namespace GlpiPlugin\Assetsynccontroller;

use Config;
use Session;

class Menu
{
    public const PAGE_PATH = '/plugins/assetsynccontroller/front/config.php';

    public static function getMenuContent(): array|false
    {
        // Hide the Setup link from profiles that cannot read GLPI configuration.
        if (!Session::haveRight(Config::$rightname, READ))
        {
            return false;
        }

        return [
            'title' => PLUGIN_ASSETSYNCCONTROLLER_NAME,
            'page' => self::PAGE_PATH,
            'icon' => 'ti ti-settings',
        ];
    }
}
