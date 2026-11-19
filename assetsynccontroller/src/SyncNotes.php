<?php

namespace GlpiPlugin\Assetsynccontroller;

use CommonDBTM;
use Glpi\DBAL\QueryExpression;
use Glpi\RichText\RichText;
use Notepad;
use Session;

final class SyncNotes
{
    public static function isField(array $descriptor): bool
    {
        return ($descriptor['id'] ?? null) === 200
            && ($descriptor['table'] ?? null) === Notepad::getTable()
            && ($descriptor['field'] ?? null) === 'content';
    }

    public static function normalize(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value))
        {
            throw new SyncException('invalid_field_value', 422);
        }
        $bodies = [];
        foreach ($value as $body)
        {
            if (!is_string($body))
            {
                throw new SyncException('invalid_field_value', 422);
            }
            $body = self::cleanBody($body);
            if ($body !== '')
            {
                $bodies[] = $body;
            }
        }
        $bodies = array_values(array_unique($bodies));
        sort($bodies, SORT_STRING);
        return $bodies;
    }

    private static function cleanBody(string $body): string
    {
        $body = $body === '0' ? $body : RichText::getSafeHtml($body);
        // Embedded images and GLPI document links refer to files on the source server.
        $body = preg_replace('~<img\b[^>]*>~i', '', $body);
        $body = preg_replace('~<a\b[^>]*\bhref="[^"]*(?:document\.send\.php|document\.php)[^"]*"[^>]*>(.*?)</a>~is', '$1', $body);
        return trim($body);
    }

    public static function read(CommonDBTM $asset): array
    {
        global $DB;

        $bodies = [];
        $lastEdit = null;
        foreach ($DB->request([
            'SELECT' => ['content', new QueryExpression('UNIX_TIMESTAMP(COALESCE(date_mod, date_creation)) AS changed_at')],
            'FROM' => Notepad::getTable(),
            'WHERE' => ['itemtype' => $asset::class, 'items_id' => $asset->getID()],
        ]) as $note)
        {
            $body = self::cleanBody((string) $note['content']);
            if ($body === '')
            {
                continue;
            }
            $bodies[] = $body;
            if ($note['changed_at'] !== null)
            {
                $lastEdit = max($lastEdit ?? 0, (int) $note['changed_at']);
            }
        }
        return ['value' => self::normalize($bodies), 'kind' => 'notes', 'modified_at' => $lastEdit];
    }

    public static function append(CommonDBTM $asset, array $bodies): array
    {
        if (!Session::haveRight($asset::$rightname, READNOTE) || !Session::haveRight($asset::$rightname, UPDATENOTE))
        {
            throw new SyncException('field_read_only', 403);
        }

        $current = self::read($asset)['value'];
        $expected = self::normalize(array_merge($current, $bodies));
        foreach (array_diff($expected, $current) as $body)
        {
            // Create local Notes records without sharing them with linked tickets.
            $note = new Notepad();
            $input = [
                'itemtype' => $asset::class,
                'items_id' => $asset->getID(),
                'content' => $body,
                'visible_from_ticket' => 0,
                '_no_history' => true,
            ];
            if (!$note->can(-1, CREATE, $input))
            {
                throw new SyncException('field_read_only', 403);
            }
            if (!$note->add($input, [], false))
            {
                throw new SyncException('notes_write_failed', 422);
            }
        }
        if (array_diff($expected, self::read($asset)['value']) !== [])
        {
            throw new SyncException('notes_write_failed', 422);
        }
        return $expected;
    }
}
