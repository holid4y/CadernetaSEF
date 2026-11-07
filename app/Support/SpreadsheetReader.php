<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first sheet of an .xlsx file, or a .csv file, as rows of strings
 * without an external package. Shared strings, inline strings and numbers
 * are supported; formulas come back as their last computed value.
 */
final class SpreadsheetReader
{
    // Even a small upload can unpack into gigabytes of XML.
    private const MAX_XML_BYTES = 30_000_000;

    /**
     * @return list<list<string>> at most $limit rows, header included
     */
    public static function read(string $path, string $extension, int $limit): array
    {
        return strtolower(ltrim($extension, '.')) === 'csv'
            ? self::readCsv($path, $limit)
            : self::readXlsx($path, $limit);
    }

    /**
     * Excel in Portuguese saves CSV with semicolons and, in older versions,
     * in Windows-1252 instead of UTF-8.
     *
     * @return list<list<string>>
     */
    private static function readCsv(string $path, int $limit): array
    {
        $content = (string) file_get_contents($path);
        $content = str_starts_with($content, "\u{FEFF}") ? substr($content, 3) : $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn (string $candidate): int => substr_count($firstLine, $candidate))->first();

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];

        while (count($rows) < $limit && ($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn (mixed $value): string => (string) $value, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private static function readXlsx(string $path, int $limit): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not an .xlsx workbook.');
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheet = self::xml($zip, self::firstSheetName($zip));
        } finally {
            $zip->close();
        }

        $rows = [];

        foreach ($sheet->sheetData->row as $rowElement) {
            if (count($rows) >= $limit) {
                break;
            }

            $cells = [];

            foreach ($rowElement->c as $cell) {
                $cells[self::columnIndex((string) $cell['r'])] = self::cellValue($cell, $shared);
            }

            $rows[] = $cells === [] ? [] : array_map(
                fn (int $column): string => $cells[$column] ?? '',
                range(0, max(array_keys($cells))),
            );
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];

        foreach (self::xml($zip, 'xl/sharedStrings.xml')->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    private static function firstSheetName(ZipArchive $zip): string
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $relations = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $id = (string) ($workbook->sheets->sheet[0]?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');

        foreach ($relations->Relationship as $relation) {
            if ((string) $relation['Id'] === $id) {
                $target = ltrim((string) $relation['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : "xl/{$target}";
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $size = $zip->statName($name)['size'] ?? null;

        if ($size === null || $size > self::MAX_XML_BYTES) {
            throw new RuntimeException("{$name} is missing or too large.");
        }

        $xml = simplexml_load_string((string) $zip->getFromName($name), SimpleXMLElement::class, LIBXML_NONET);

        if ($xml === false) {
            throw new RuntimeException("{$name} is not valid XML.");
        }

        return $xml;
    }

    /**
     * @param  list<string>  $shared
     */
    private static function cellValue(SimpleXMLElement $cell, array $shared): string
    {
        $raw = (string) $cell->v;

        return match ((string) $cell['t']) {
            's' => $shared[(int) $raw] ?? '',
            'inlineStr' => self::text($cell->is),
            'str', 'b', 'e' => $raw,
            default => self::number($raw),
        };
    }

    // CPF and CNPJ typed as numbers come back as 1.2345678901E+13 or 12345678901.0.
    private static function number(string $raw): string
    {
        return preg_match('/^\d+(\.\d+)?(E\+?\d+)?$/i', $raw) && floor((float) $raw) === (float) $raw
            ? number_format((float) $raw, 0, '', '')
            : $raw;
    }

    private static function text(?SimpleXMLElement $element): string
    {
        if ($element === null) {
            return '';
        }

        $parts = $element->xpath(".//*[local-name()='t']") ?: [];

        return implode('', array_map(fn (SimpleXMLElement $part): string => (string) $part, $parts));
    }

    private static function columnIndex(string $reference): int
    {
        $index = 0;

        foreach (str_split((string) preg_replace('/\d/', '', strtoupper($reference))) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }
}
