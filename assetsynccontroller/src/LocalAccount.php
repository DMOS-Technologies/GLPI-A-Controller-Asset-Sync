<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use Config;
use Glpi\Search\SearchOption;
use Profile;
use Session;
use User;

final class LocalAccount
{
    public const CONFIG_CONTEXT = 'plugin:assetsynccontroller';
    public const CONFIG_KEY = 'local_write_user_id';

    public static function findActiveUser(int $userId): ?User
    {
        $user = new User();
        if ($userId <= 0 || !$user->getFromDB($userId) || empty($user->fields['is_active'])
            || !empty($user->fields['is_deleted']))
        {
            return null;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $begin = $user->fields['begin_date'];
        $end = $user->fields['end_date'];
        if (($begin !== null && $begin > $now) || ($end !== null && $end < $now))
        {
            return null;
        }

        return $user;
    }

    public static function run(CommonDBTM $asset, callable $write): mixed
    {
        $settings = Config::getConfigurationValues(self::CONFIG_CONTEXT, [self::CONFIG_KEY]);
        $userId = (int) ($settings[self::CONFIG_KEY] ?? 0);
        if ($userId <= 0)
        {
            throw new SyncProblem('Set the GLPI A write account under Connections before copying changes from GLPI B.');
        }
        $user = self::findActiveUser($userId);
        if ($user === null)
        {
            throw new SyncProblem('The configured GLPI A write account is unavailable or inactive.');
        }
        if (Session::isRightChecksDisabled())
        {
            throw new SyncProblem('GLPI A could not verify permissions for the configured write account.');
        }

        $session = $_SESSION;
        try
        {
            // GLPI gives cron unrestricted access and uses its name in Log. Use the account for both checks and attribution.
            unset($_SESSION['glpicronuserrunning'], $_SESSION['glpiinventoryuserrunning'], $_SESSION['impersonator_id']);
            $_SESSION['glpiID'] = $userId;
            $_SESSION['glpiname'] = $user->fields['name'];
            $_SESSION['glpirealname'] = $user->fields['realname'];
            $_SESSION['glpifirstname'] = $user->fields['firstname'];
            Session::initEntityProfiles($userId);

            $entityId = (int) $asset->fields['entities_id'];
            $ancestors = getAncestorsOf('glpi_entities', $entityId);
            foreach ($_SESSION['glpiprofiles'] as $profileId => $assignment)
            {
                $grants = $assignment['entities'];
                if (!self::allowsEntity($grants, $entityId, $ancestors))
                {
                    continue;
                }

                $profile = new Profile();
                if (!$profile->getFromDB($profileId))
                {
                    continue;
                }
                $profile->cleanProfile();
                $_SESSION['glpiactiveprofile'] = $profile->fields + ['entities' => $grants];
                if (!Session::changeActiveEntities($entityId, false))
                {
                    continue;
                }

                // Fields search options depend on the profile and can have been loaded under cron.
                SearchOption::clearSearchOptionCache($asset::class);
                if ($asset->can($asset->getID(), READ) && $asset->can($asset->getID(), UPDATE))
                {
                    return $write();
                }
            }

            throw new SyncProblem('The configured GLPI A write account needs read and update permissions for this asset in the route entity.');
        }
        finally
        {
            $_SESSION = $session;
            SearchOption::clearSearchOptionCache($asset::class);
        }
    }

    private static function allowsEntity(array $grants, int $entityId, array $ancestors): bool
    {
        if (isset($grants[$entityId]))
        {
            return true;
        }

        // A grant on an ancestor only applies when that profile grant is recursive.
        foreach ($ancestors as $ancestorId)
        {
            if (!empty($grants[$ancestorId]['is_recursive']))
            {
                return true;
            }
        }
        return false;
    }
}
