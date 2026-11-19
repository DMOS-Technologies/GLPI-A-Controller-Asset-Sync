<?php

namespace GlpiPlugin\Assetsynccontroller;

final class BillingHistory
{
    public static function getTypeName(int $nb = 0): string
    {
        return __('Asset billing', 'assetsynccontroller');
    }

    public static function getHistoryEntry(array $row): string
    {
        // Use the same field visibility and formatting checks as sync history.
        return SyncHistory::getHistoryEntry($row);
    }
}
