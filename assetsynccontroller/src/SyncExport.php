<?php

namespace GlpiPlugin\Assetsynccontroller;

use Html;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use XMLWriter;
use ZipArchive;

final class SyncExport
{
    private const HEADERS = ['Asset', 'Asset Type', 'Serial Number', 'Status', 'Reason', 'Sync Direction', 'Last Updated'];
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public static function response(string $format, iterable $rows, array $assetTypes, array $statuses, array $directions): Response
    {
        $exportRows = self::exportRows($rows, $assetTypes, $statuses, $directions);
        $write = match ($format) {
            'csv' => static fn() => self::writeCsv($exportRows),
            'xlsx' => static fn() => self::writeXlsx($exportRows),
            default => throw new InvalidArgumentException('Invalid sync export format'),
        };

        return new StreamedResponse($write, Response::HTTP_OK, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : self::XLSX_MIME,
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', "sync-information.$format"),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function exportRows(iterable $rows, array $assetTypes, array $statuses, array $directions): iterable
    {
        yield self::HEADERS;
        foreach ($rows as $row)
        {
            yield [
                (string) ($row['asset_name'] ?: '#' . $row['assets_id']),
                (string) ($assetTypes[$row['asset_type']] ?? $row['asset_type']),
                (string) $row['serial'],
                (string) ($statuses[$row['status']]['label'] ?? $row['status']),
                (string) ($row['last_error'] ?? ''),
                (string) ($directions[$row['sync_direction'] ?? ''] ?? ''),
                isset($row['date_mod']) ? (string) Html::convDateTime($row['date_mod'], null, true) : '',
            ];
        }
    }

    private static function writeCsv(iterable $rows): void
    {
        $output = fopen('php://output', 'wb');
        if ($output === false)
        {
            throw new RuntimeException('Could not open the CSV output');
        }

        try
        {
            // The UTF-8 marker keeps accents and direction arrows readable in Excel.
            fwrite($output, "\xEF\xBB\xBF");
            foreach ($rows as $cells)
            {
                // Spreadsheet applications may run formulas even in correctly quoted CSV cells.
                $cells = array_map(static function (string $value): string
                {
                    return preg_match('/^(?:[\t\r\n]|[\s\p{Cc}\x{FEFF}]*[=+@\-＝＋＠－])/u', $value) === 1
                        ? "'" . $value
                        : $value;
                }, $cells);
                fputcsv($output, $cells, escape: '', eol: "\r\n");
            }
        }
        finally
        {
            fclose($output);
        }
    }

    private static function writeXlsx(iterable $rows): void
    {
        $worksheet = tempnam(sys_get_temp_dir(), 'asc-sheet-');
        $workbook = false;
        try
        {
            $workbook = tempnam(sys_get_temp_dir(), 'asc-workbook-');
            if ($worksheet === false || $workbook === false)
            {
                throw new RuntimeException('Could not create temporary export files');
            }

            // Stream cells to disk so export memory stays bounded for routes with many assets.
            self::writeWorksheet($worksheet, $rows);
            self::packageWorkbook($workbook, $worksheet);
            if (readfile($workbook) === false)
            {
                throw new RuntimeException('Could not send the workbook');
            }
        }
        finally
        {
            if ($worksheet !== false)
            {
                unlink($worksheet);
            }
            if ($workbook !== false)
            {
                unlink($workbook);
            }
        }
    }

    private static function writeWorksheet(string $path, iterable $rows): void
    {
        $xml = new XMLWriter();
        if (!$xml->openUri($path))
        {
            throw new RuntimeException('Could not open the worksheet');
        }

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('worksheet');
        $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetData');
        $rowNumber = 0;
        foreach ($rows as $cells)
        {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) ++$rowNumber);
            foreach ($cells as $column => $value)
            {
                // Inline strings keep serial numbers intact and never turn asset names into formulas.
                $xml->startElement('c');
                $xml->writeAttribute('r', chr(ord('A') + $column) . $rowNumber);
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
                // Escape spreadsheet character markers when they are part of the literal asset data.
                $xml->text(preg_replace('/_(x[0-9a-fA-F]{4}_)/', '_x005F_$1', $clean) ?? $clean);
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    private static function packageWorkbook(string $path, string $worksheet): void
    {
        // The package parts below describe a workbook with one worksheet and inline text cells.
        $parts = [
            '[Content_Types].xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                    <Default Extension="xml" ContentType="application/xml"/>
                    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                </Types>
                XML,
            '_rels/.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
                </Relationships>
                XML,
            'xl/workbook.xml' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                    <sheets><sheet name="Sync Information" sheetId="1" r:id="rId1"/></sheets>
                </workbook>
                XML,
            'xl/_rels/workbook.xml.rels' => <<<'XML'
                <?xml version="1.0" encoding="UTF-8"?>
                <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
                </Relationships>
                XML,
        ];

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
        {
            throw new RuntimeException('Could not create the workbook');
        }

        try
        {
            foreach ($parts as $name => $xml)
            {
                if (!$zip->addFromString($name, $xml))
                {
                    throw new RuntimeException('Could not save workbook metadata');
                }
            }
            if (!$zip->addFile($worksheet, 'xl/worksheets/sheet1.xml'))
            {
                throw new RuntimeException('Could not save the workbook worksheet');
            }
        }
        finally
        {
            if (!$zip->close())
            {
                throw new RuntimeException('Could not finish writing the workbook');
            }
        }
    }
}
