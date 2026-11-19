<?php

namespace GlpiPlugin\Assetsynccontroller;

use DateTimeImmutable;
use DateTimeZone;

final class BillingRules
{
    private const TIMEZONE = 'Asia/Brunei';
    private const TERM_MONTHS = 48;
    private const SAME_MONTH_LAST_DAY = 5;
    private const EXCLUDED_STATUS = ['In Stock - Available', 'Retired - Disposed', 'Retired - Pending Disposal'];

    public static function hardware(array $values, ?string $frequency, ?DateTimeImmutable $today = null): array
    {
        $installed = self::date($values['installed_date'] ?? null);
        // The billable flag depends on installation even when the schedule cannot apply.
        $result = [
            'hw_billable' => $installed === null ? 0 : 1,
            'billing_start_date' => null,
            'billing_end_date' => null,
            'frequency' => null,
            'billing_month' => 0,
        ];
        $ownership = trim((string) ($values['ownership'] ?? ''));
        $status = trim((string) ($values['status'] ?? ''));
        if ($installed === null || $ownership !== 'Customer Leased'
            || in_array($status, self::EXCLUDED_STATUS, true)
            || !in_array($frequency, ['Monthly', 'Annual'], true))
        {
            return $result;
        }

        $start = self::startDate($installed);
        $end = $start->modify('+' . self::TERM_MONTHS . ' months')->modify('-1 day');
        $timezone = new DateTimeZone(self::TIMEZONE);
        $today = $today === null ? new DateTimeImmutable('now', $timezone) : $today->setTimezone($timezone);
        // Compare calendar months in Brunei. The start month counts as month one.
        $months = 12 * ((int) $today->format('Y') - (int) $start->format('Y'))
            + ((int) $today->format('n') - (int) $start->format('n'));

        $result['billing_start_date'] = $start->format('Y-m-d');
        $result['billing_end_date'] = $end->format('Y-m-d');
        $result['frequency'] = $frequency;
        $result['billing_month'] = max(0, min(self::TERM_MONTHS, $months + 1));
        return $result;
    }

    public static function software(array $values): array
    {
        $redeployed = self::date($values['redeployed_date'] ?? null);
        $billable = trim((string) ($values['status'] ?? '')) === 'In Use' && $redeployed !== null;
        return [
            'swsd_billable' => $billable ? 1 : 0,
            'billing_start_date' => $billable ? self::startDate($redeployed)->format('Y-m-d') : null,
        ];
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        // A resolved GLPI date can have a time suffix. Check the date before PHP can roll it forward.
        if (!is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})/', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))
        {
            return null;
        }
        return new DateTimeImmutable($parts[0], new DateTimeZone(self::TIMEZONE));
    }

    private static function startDate(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = (int) $date->format('j') <= self::SAME_MONTH_LAST_DAY ? 'this' : 'next';
        return $date->modify('first day of ' . $month . ' month');
    }
}
