<?php

namespace GlpiPlugin\Assetsynccontroller;

use DateTimeImmutable;
use DateTimeZone;

final class SyncValue
{
    public static function forType(mixed $value, string $kind): mixed
    {
        if ($value === null || $value === '')
        {
            return $kind === 'dropdown_list' ? [] : null;
        }

        return match ($kind) {
            'text' => self::text($value),
            'dropdown' => self::dropdown($value),
            'dropdown_list' => self::dropdownList($value),
            'boolean' => self::boolean($value),
            'integer' => self::integer($value),
            'number' => self::number($value),
            'date', 'datetime' => self::date($value, $kind === 'datetime'),
            'item' => $value, // The destination field resolves the itemtype and record together.
            default => throw new SyncException('invalid_field_value', 422),
        };
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value))
        {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value))
        {
            return (string) $value;
        }
        if (is_array($value) && is_string($value['label'] ?? null))
        {
            return $value['label'];
        }
        if (is_array($value) && array_is_list($value))
        {
            return implode(', ', array_map(self::text(...), $value));
        }
        throw new SyncException('invalid_field_value', 422);
    }

    private static function dropdownList(mixed $value): array
    {
        if (is_string($value) && str_starts_with(ltrim($value), '['))
        {
            $list = json_decode($value, true);
            if (is_array($list) && array_is_list($list))
            {
                $value = $list;
            }
        }
        if (!is_array($value) || !array_is_list($value))
        {
            $value = [$value];
        }
        return array_values(array_unique(array_map(self::text(...), $value)));
    }

    private static function dropdown(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value))
        {
            if (count($value) > 1)
            {
                throw new SyncException('invalid_reference_value', 422);
            }
            $value = $value[0] ?? null;
        }
        $label = $value === null ? '' : trim(self::text($value));
        return $label === '' ? null : $label;
    }

    private static function boolean(mixed $value): bool
    {
        return match (strtolower(trim(self::text($value)))) {
            'yes', 'true', 'on', '1' => true,
            'no', 'false', 'off', '0' => false,
            default => throw new SyncException('invalid_field_value', 422),
        };
    }

    private static function integer(mixed $value): int
    {
        if (is_bool($value))
        {
            return (int) $value;
        }
        $input = trim(self::text($value));
        // Allow leading zeroes without allowing a fractional or out-of-range number.
        if (!preg_match('/\A[+-]?\d+\z/', $input))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        $digits = ltrim(ltrim($input, '+-'), '0');
        $normalized = (str_starts_with($input, '-') ? '-' : '') . ($digits === '' ? '0' : $digits);
        $result = filter_var($normalized, FILTER_VALIDATE_INT);
        if ($result === false)
        {
            throw new SyncException('invalid_field_value', 422);
        }
        return $result;
    }

    private static function number(mixed $value): string
    {
        if (is_bool($value))
        {
            return (string) (int) $value;
        }
        $input = trim(self::text($value));
        if (!preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)\z/', $input))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        // Preserve decimal precision rather than converting through a floating-point number.
        $negative = str_starts_with($input, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($input, '+-'), 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $result = $whole . ($fraction === '' ? '' : '.' . $fraction);
        return $negative && $result !== '0' ? '-' . $result : $result;
    }

    private static function date(mixed $value, bool $withTime): string
    {
        $input = trim(self::text($value));
        if (!preg_match('/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})?)?\z/', $input))
        {
            throw new SyncException('invalid_field_value', 422);
        }

        $utc = new DateTimeZone('UTC');
        $value = new DateTimeImmutable($input, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        return $withTime ? $value->setTimezone($utc)->format('Y-m-d H:i:s') : $value->format('Y-m-d');
    }
}
