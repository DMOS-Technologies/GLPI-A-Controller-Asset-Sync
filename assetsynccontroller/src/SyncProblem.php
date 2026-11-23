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

    public static function storedGuidance(string $message): ?string
    {
        // Older failed rows have no structured guidance. Explain only errors we can identify reliably.
        return match ($message) {
            'The newest edit could not be established for a bidirectional field.' =>
                'A and B had different values and the saved mapping uses Newer Edit Wins. The sync could not establish which edit was later. Save the intended value as a new edit, or choose a fixed direction in Mappings, then retry.',
            'The serial number is ambiguous in the mapped GLPI A entity.' =>
                'Find the duplicate serial in the mapped entity and asset type on GLPI A. Give distinct assets unique serials, then retry.',
            'The serial number matches more than one asset in the mapped GLPI B entity.' =>
                'Find the duplicate serial in the mapped entity and asset type on GLPI B. Resolve the duplicate so Agent B can select one record, then retry.',
            'Agent B could not resolve a mapped dropdown value in the destination entity.',
            'GLPI A could not resolve a mapped dropdown value in the route entity.' =>
                'Check that the destination dropdown contains exactly one matching option visible in the mapped entity, then retry.',
            'Could not authenticate to Agent B.',
            'Agent B did not acknowledge this sync request.' =>
                'Check the saved connection under Connections and confirm Agent B is reachable, then retry.',
            default => null,
        };
    }
}
