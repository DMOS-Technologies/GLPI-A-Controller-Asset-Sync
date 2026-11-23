<?php

namespace GlpiPlugin\Assetsynccontroller;

use Profile;
use ProfileRight;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class Access
{
    public const SECTIONS = [
        'connections' => 'Connections',
        'routes' => 'Routes',
        'mappings' => 'Mappings',
        'sync-information' => 'Sync Information',
        'billing-configuration' => 'Billing Configuration',
    ];

    public static function right(string $section): string
    {
        return 'plugin_assetsynccontroller_' . str_replace('-', '_', $section);
    }

    public static function canRead(string $section): bool
    {
        return (self::permissions($section) & READ) === READ;
    }

    public static function canUpdate(string $section): bool
    {
        global $DB;

        $required = READ | UPDATE;
        return !$DB->isSlave() && (self::permissions($section) & $required) === $required;
    }

    public static function checkRead(string $section): void
    {
        if (!self::canRead($section))
        {
            throw new AccessDeniedHttpException('Your GLPI profile cannot view this Asset Sync Controller section.');
        }
    }

    public static function checkUpdate(string $section): void
    {
        if (!self::canUpdate($section))
        {
            throw new AccessDeniedHttpException('Your GLPI profile cannot manage this Asset Sync Controller section.');
        }
    }

    public static function visibleSections(): array
    {
        return array_filter(self::SECTIONS, self::canRead(...), ARRAY_FILTER_USE_KEY);
    }

    private static function permissions(string $section): int
    {
        static $profileRights = [];

        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0)
        {
            return 0;
        }

        // Read the saved profile rights once per request so open sessions honor a revocation.
        $profileRights[$profileId] ??= ProfileRight::getProfileRights(
            $profileId,
            array_map(self::right(...), array_keys(self::SECTIONS)),
        );
        return (int) ($profileRights[$profileId][self::right($section)] ?? 0);
    }

    public static function install(): bool
    {
        global $DB;

        $administrators = array_fill_keys(Profile::getSuperAdminProfilesId(), true);
        $names = array_map(self::right(...), array_keys(self::SECTIONS));
        $table = ProfileRight::getTable();
        $existing = [];
        foreach ($DB->request(['FROM' => $table, 'WHERE' => ['name' => $names]]) as $right)
        {
            $existing[$right['profiles_id']][$right['name']] = true;
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]) as $profile)
        {
            $id = (int) $profile['id'];
            foreach ($names as $name)
            {
                if (isset($existing[$id][$name]))
                {
                    continue;
                }
                // GLPI's super-administrator profiles can delegate access. All other profiles start with none.
                $right = new ProfileRight();
                if (!$right->add([
                    'profiles_id' => $id,
                    'name' => $name,
                    'rights' => isset($administrators[$id]) ? READ | UPDATE : 0,
                ]))
                {
                    return false;
                }
            }
        }
        ProfileRight::cleanAllPossibleRights();
        return true;
    }

    public static function uninstall(): void
    {
        ProfileRight::deleteProfileRights(array_map(self::right(...), array_keys(self::SECTIONS)));
    }
}
