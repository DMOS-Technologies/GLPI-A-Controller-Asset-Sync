<?php

namespace GlpiPlugin\Assetsynccontroller;

use RuntimeException;
use Throwable;

// Only safe, user-facing sync errors use this exception.
class SyncProblem extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly array $details = [],
    )
    {
        parent::__construct($message, $code, $previous);
    }

    public static function dropdown(string $message, string $side, array $field, mixed $value = null): self
    {
        $name = $field['name'] ?? $field['field'] ?? '';
        if (!is_string($name) || $name === '')
        {
            return new self($message);
        }

        $details = ['field' => self::shortLabel($name), 'side' => $side];
        // Show only submitted portable values, without structured references or entire payloads.
        if (is_scalar($value))
        {
            $label = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
            $details['value'] = self::shortLabel($label);
        }
        elseif (is_array($value) && array_is_list($value)
            && count(array_filter($value, 'is_string')) === count($value))
        {
            $details['value'] = self::shortLabel(implode(', ', $value));
        }

        return new self($message, details: $details);
    }

    private static function shortLabel(string $label): string
    {
        return mb_strlen($label) > 500 ? mb_substr($label, 0, 500) . '…' : $label;
    }
}
