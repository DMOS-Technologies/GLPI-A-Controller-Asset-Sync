<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonGLPI;
use Html;
use Profile as GlpiProfile;

final class Profile extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof GlpiProfile || !$item->can($item->getID(), READ))
        {
            return '';
        }

        return self::createTabEntry('Asset Sync Controller', icon: 'ti ti-lock');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof GlpiProfile)
        {
            return false;
        }
        $item->check($item->getID(), READ);
        $canEdit = $item->can($item->getID(), UPDATE);

        $rights = [];
        foreach (Access::SECTIONS as $section => $label)
        {
            $rights[] = [
                'label' => $label,
                'field' => Access::right($section),
                'rights' => [READ => 'View', UPDATE => 'Manage'],
            ];
        }

        // GLPI's profile form checks update rights and CSRF before saving the permission matrix.
        echo '<form method="post" action="' . htmlescape(GlpiProfile::getFormURL()) . '">';
        echo '<input type="hidden" name="id" value="' . (int) $item->getID() . '">';
        echo '<p class="text-secondary">Select View for each section that should appear in the menu. Select View and Manage to allow changes in that section.</p>';
        $item->displayRightsChoiceMatrix($rights, [
            'title' => 'Asset Sync Controller access',
            'canedit' => $canEdit,
        ]);
        if ($canEdit)
        {
            echo '<button type="submit" name="update" value="1" class="btn btn-primary">' . __s('Save') . '</button>';
        }
        Html::closeForm();

        return true;
    }
}
