<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Modules\People\Exceptions\TeamImportException;
use XMLReader;
use ZipArchive;

final class TeamSpreadsheet
{
    public const COLUMNS = ['first_name', 'last_name', 'email', 'role', 'department', 'timezone', 'schedule'];

    public const REQUIRED = ['first_name', 'last_name', 'email', 'role'];

    public const EXAMPLES = [
        ['Ayesha', 'Khan', 'ayesha.khan@example.com', 'member', 'Quote Operations', 'Asia/Karachi', 'default'],
        ['Bilal', 'Ahmed', 'bilal.ahmed@example.com', 'member: Quote Lead', 'Customer Support', 'Asia/Karachi', 'ayesha.khan@example.com'],
        ['Partner', 'Agency', 'ops@partner-agency.example', 'client: Agency Partner', '', '', ''],
    ];

    public static function read(string $path, string $extension, int $maxRows): array
    {
        $rows = match ($extension) {
            'csv', 'txt' => self::readCsv($path, $maxRows),
            'xlsx' => self::readXlsx($path, $maxRows),
            default => throw TeamImportException::unsupportedFile(),
        };

        $header = array_map(fn ($value) => self::headerKey((string) $value), array_shift($rows) ?? []);
        $missing = array_diff(self::REQUIRED, $header);

        if ($missing !== []) {
            throw TeamImportException::missingColumns(array_values($missing));
        }

        $records = [];
        foreach ($rows as $index => $cells) {
            $record = [];
            foreach ($header as $position => $key) {
                if (in_array($key, self::COLUMNS, true)) {
                    $record[$key] = trim((string) ($cells[$position] ?? ''));
                }
            }

            if (implode('', $record) !== '') {
                $records[$index + 2] = $record;
            }
        }

        if (count($records) > $maxRows) {
            throw TeamImportException::tooManyRows($maxRows);
        }

        return $records;
    }

    public static function templateXlsx(): string
    {
        $sheetRows = [self::COLUMNS, ...self::EXAMPLES];
        $xmlRows = '';
        foreach ($sheetRows as $rowIndex => $values) {
            $cells = '';
            foreach ($values as $columnIndex => $value) {
                $reference = self::columnLetter($columnIndex).($rowIndex + 1);
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells .= '<c r="'.$reference.'" t="inlineStr"'.$style.'><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xmlRows .= '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
        }

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Teammates" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="7" width="26" customWidth="1"/></cols><sheetData>'.$xmlRows.'</sheetData></worksheet>',
        ];

        $path = tempnam(sys_get_temp_dir(), 'team-template');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $binary = (string) file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    public static function templateCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        foreach ([self::COLUMNS, ...self::EXAMPLES] as $row) {
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    private static function readCsv(string $path, int $maxRows): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw TeamImportException::unreadable();
        }

        $rows = [];
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            if (count($rows) > $maxRows + 50) {
                fclose($handle);
                throw TeamImportException::tooManyRows($maxRows);
            }
            $rows[] = $cells;
        }
        fclose($handle);

        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $rows[0][0]);
        }

        return $rows;
    }

    private static function readXlsx(string $path, int $maxRows): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw TeamImportException::unreadable();
        }

        $sheetPath = self::firstSheetPath($zip);
        $shared = self::sharedStrings($zip);
        $xml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($xml === false) {
            throw TeamImportException::unreadable();
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $current = null;
        $column = 0;
        $type = null;
        $value = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                $current = [];
                if (count($rows) > $maxRows + 50) {
                    throw TeamImportException::tooManyRows($maxRows);
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $column = self::columnIndex((string) $reader->getAttribute('r'), $current === null ? 0 : count($current));
                $type = $reader->getAttribute('t');
                $value = '';
                if ($reader->isEmptyElement && $current !== null) {
                    $current[$column] = '';
                }
            } elseif ($reader->nodeType === XMLReader::ELEMENT && ($reader->localName === 'v' || $reader->localName === 't')) {
                $value .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'c' && $current !== null) {
                $current[$column] = $type === 's' ? ($shared[(int) $value] ?? '') : $value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'row' && $current !== null) {
                $filled = $current === [] ? [] : array_replace(array_fill(0, max(array_keys($current)) + 1, ''), $current);
                $rows[] = $filled;
                $current = null;
            }
        }
        $reader->close();

        return $rows;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $relations === false) {
            throw TeamImportException::unreadable();
        }

        preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $sheet);
        preg_match_all('/<Relationship\b[^>]*>/', $relations, $links);

        foreach ($links[0] as $link) {
            preg_match('/\bId="([^"]+)"/', $link, $id);
            preg_match('/\bTarget="([^"]+)"/', $link, $target);
            if (isset($sheet[1], $id[1], $target[1]) && $id[1] === $sheet[1]) {
                $relative = ltrim(str_replace('\\', '/', $target[1]), '/');
                $resolved = str_starts_with($relative, 'xl/') ? $relative : 'xl/'.$relative;

                if (str_contains($resolved, '..')) {
                    throw TeamImportException::unreadable();
                }

                return $resolved;
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $text = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $text = '';
            } elseif ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $text !== null) {
                $text .= $reader->readString();
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = (string) $text;
                $text = null;
            }
        }
        $reader->close();

        return $strings;
    }

    private static function headerKey(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($value))), '_');
    }

    private static function columnIndex(string $reference, int $fallback): int
    {
        if (preg_match('/^([A-Z]+)\d+$/', $reference, $match) !== 1) {
            return $fallback;
        }

        $index = 0;
        foreach (str_split($match[1]) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    private static function columnLetter(int $index): string
    {
        $letters = '';
        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $letters = chr(65 + ($index - 1) % 26).$letters;
        }

        return $letters;
    }
}
