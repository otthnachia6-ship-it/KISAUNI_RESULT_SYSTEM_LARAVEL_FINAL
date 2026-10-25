<?php

namespace App\Services;

use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Small, dependency-free reader/writer for Excel .xlsx files (needs only the PHP zip and
 * SimpleXML extensions). Used for the student-registration and marks templates.
 *
 * Writer: sheets of text/number cells, a few styles, column widths, drop-down lists.
 * Reader: returns every cell as trimmed text. Dangerous files (zip bombs, DOCTYPE/entities,
 * too many rows) are rejected with an InvalidArgumentException whose message is safe to show.
 */
class Xlsx
{
    public const MAX_FILE_BYTES = 2 * 1024 * 1024;
    public const MAX_UNZIPPED_BYTES = 30 * 1024 * 1024;
    public const MAX_ROWS = 2000;
    public const MAX_COLS = 30;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    // style ids used by build()
    public const S_DEFAULT = 0;
    public const S_TEXT = 1;      // "@" text format (keeps leading zeros)
    public const S_HEADER = 2;    // white bold on blue
    public const S_TITLE = 3;     // big bold
    public const S_LABEL = 4;     // bold
    public const S_INPUT = 5;     // yellow input cell
    public const S_INPUT_TEXT = 6; // yellow input cell, text format

    // ------------------------------------------------------------------ writer

    /**
     * @param array<int,array{name:string,rows:array,cols?:array,validations?:array,hidden?:bool,freeze?:bool}> $sheets
     *   A cell is a scalar, or ['v' => scalar|null, 's' => style id].
     */
    public static function build(array $sheets): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new InvalidArgumentException('The server cannot create Excel files (PHP zip extension missing).');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new InvalidArgumentException('The Excel file could not be created.');
        }

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $state = !empty($sheet['hidden']) ? ' state="hidden"' : '';
            $wbSheets .= '<sheet name="' . self::esc(mb_substr($sheet['name'], 0, 31)) . '" sheetId="' . $n . '"' . $state . ' r:id="rId' . $n . '"/>';
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::sheetXml($sheet, $i === 0));
        }
        $styleRel = count($sheets) + 1;
        $wbRels .= '<Relationship Id="rId' . $styleRel . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $ct .= '</Types>';

        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wbSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    private static function sheetXml(array $sheet, bool $selected): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . self::NS_MAIN . '">';
        $xml .= '<sheetViews><sheetView workbookViewId="0"' . ($selected ? ' tabSelected="1"' : '') . '>';
        if (!empty($sheet['freeze'])) {
            $row = (int) $sheet['freeze'] > 1 ? (int) $sheet['freeze'] : 1;
            $xml .= '<pane ySplit="' . $row . '" topLeftCell="A' . ($row + 1) . '" activePane="bottomLeft" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';

        if (!empty($sheet['cols'])) {
            $xml .= '<cols>';
            foreach ($sheet['cols'] as $i => $c) {
                $n = $i + 1;
                $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . ($c['width'] ?? 14) . '" customWidth="1"'
                    . (isset($c['style']) ? ' style="' . (int) $c['style'] . '"' : '') . '/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach (array_values($sheet['rows']) as $r => $cells) {
            $rn = $r + 1;
            $xml .= '<row r="' . $rn . '">';
            foreach (array_values($cells) as $c => $cell) {
                $style = null;
                $value = $cell;
                if (is_array($cell)) {
                    $value = $cell['v'] ?? null;
                    $style = $cell['s'] ?? null;
                }
                $ref = self::colLetter($c) . $rn;
                $s = $style !== null ? ' s="' . (int) $style . '"' : '';
                if ($value === null || $value === '') {
                    if ($style !== null) {
                        $xml .= '<c r="' . $ref . '"' . $s . '/>';
                    }
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '"' . $s . '><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (!empty($sheet['validations'])) {
            $xml .= '<dataValidations count="' . count($sheet['validations']) . '">';
            foreach ($sheet['validations'] as $v) {
                $xml .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Not allowed" error="'
                    . self::esc($v['error'] ?? 'Choose a value from the list.') . '" sqref="' . self::esc($v['sqref']) . '">'
                    . '<formula1>' . self::esc($v['list']) . '</formula1></dataValidation>';
            }
            $xml .= '</dataValidations>';
        }

        return $xml . '</worksheet>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF9DB"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="7">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="49" fontId="0" fillId="3" borderId="0" xfId="0" applyNumberFormat="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function colLetter(int $index): string
    {
        $s = '';
        $n = $index + 1;
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    private static function esc(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------ reader

    /**
     * @return array<string,array<int,array<int,string>>> sheet name => [row number (1-based) => [column index (0-based) => text]]
     */
    public static function read(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new InvalidArgumentException('The server cannot read Excel files (PHP zip extension missing).');
        }
        if (!is_file($path) || filesize($path) === 0) {
            throw new InvalidArgumentException('The file is empty or could not be read.');
        }
        if (filesize($path) > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException('The Excel file is too large (maximum 2 MB).');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('This is not a valid Excel (.xlsx) file. Please use the template downloaded from the system and save it as .xlsx.');
        }

        try {
            if ($zip->numFiles > 200) {
                throw new InvalidArgumentException('This Excel file is not valid.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $total += (int) ($st['size'] ?? 0);
                if (str_contains((string) ($st['name'] ?? ''), '..')) {
                    throw new InvalidArgumentException('This Excel file is not valid.');
                }
            }
            if ($total > self::MAX_UNZIPPED_BYTES) {
                throw new InvalidArgumentException('This Excel file is too large to read.');
            }

            $workbook = self::xml($zip, 'xl/workbook.xml');
            $rels = self::xml($zip, 'xl/_rels/workbook.xml.rels');
            if (!$workbook || !$rels) {
                throw new InvalidArgumentException('This is not a valid Excel (.xlsx) file. Please use the template downloaded from the system and save it as .xlsx.');
            }

            $targets = [];
            $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
            foreach ($rels->xpath('//p:Relationship') ?: [] as $rel) {
                $targets[(string) $rel['Id']] = (string) $rel['Target'];
            }

            $shared = self::sharedStrings($zip);
            $out = [];
            $workbook->registerXPathNamespace('m', self::NS_MAIN);
            foreach ($workbook->xpath('//m:sheets/m:sheet') ?: [] as $i => $sheet) {
                if ($i >= 10) {
                    break;
                }
                $name = (string) $sheet['name'];
                $rid = (string) $sheet->attributes(self::NS_REL)['id'];
                $target = $targets[$rid] ?? null;
                if (!$target) {
                    continue;
                }
                $part = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
                $xml = self::xml($zip, $part);
                if ($xml) {
                    $out[$name] = self::rows($xml, $shared);
                }
            }

            return $out;
        } finally {
            $zip->close();
        }
    }

    private static function xml(ZipArchive $zip, string $name): ?SimpleXMLElement
    {
        $raw = $zip->getFromName($name);
        if ($raw === false) {
            return null;
        }
        if (stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('This Excel file is not valid.');
        }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($raw, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $x ?: null;
    }

    /** @return string[] */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $x = self::xml($zip, 'xl/sharedStrings.xml');
        if (!$x) {
            return [];
        }
        $x->registerXPathNamespace('m', self::NS_MAIN);
        $list = [];
        foreach ($x->xpath('//m:si') ?: [] as $si) {
            $si->registerXPathNamespace('m', self::NS_MAIN);
            $text = '';
            foreach ($si->xpath('m:t | m:r/m:t') ?: [] as $t) {
                $text .= (string) $t;
            }
            $list[] = $text;
        }

        return $list;
    }

    /** @return array<int,array<int,string>> */
    private static function rows(SimpleXMLElement $sheet, array $shared): array
    {
        $sheet->registerXPathNamespace('m', self::NS_MAIN);
        $rows = [];
        $cells = 0;
        foreach ($sheet->xpath('//m:sheetData/m:row') ?: [] as $row) {
            $rowNum = (int) $row['r'];
            if ($rowNum < 1) {
                continue;
            }
            if ($rowNum > self::MAX_ROWS) {
                if (self::rowHasData($row, $shared)) {
                    throw new InvalidArgumentException('The Excel file has too many rows (maximum ' . self::MAX_ROWS . ').');
                }
                continue;
            }
            $row->registerXPathNamespace('m', self::NS_MAIN);
            $values = [];
            foreach ($row->xpath('m:c') ?: [] as $c) {
                if (++$cells > 100000) {
                    throw new InvalidArgumentException('This Excel file is too large to read.');
                }
                $col = self::colIndex((string) $c['r']);
                if ($col < 0 || $col >= self::MAX_COLS) {
                    continue;
                }
                $text = self::cellText($c, $shared);
                if ($text !== '') {
                    $values[$col] = $text;
                }
            }
            if ($values) {
                $rows[$rowNum] = $values;
            }
        }

        return $rows;
    }

    private static function rowHasData(SimpleXMLElement $row, array $shared): bool
    {
        $row->registerXPathNamespace('m', self::NS_MAIN);
        foreach ($row->xpath('m:c') ?: [] as $c) {
            if (self::cellText($c, $shared) !== '') {
                return true;
            }
        }

        return false;
    }

    private static function cellText(SimpleXMLElement $c, array $shared): string
    {
        $c->registerXPathNamespace('m', self::NS_MAIN);
        $type = (string) $c['t'];
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($c->xpath('m:is/m:t | m:is/m:r/m:t') ?: [] as $t) {
                $text .= (string) $t;
            }

            return trim($text);
        }
        $v = isset($c->v) ? (string) $c->v : '';
        if ($v === '') {
            return '';
        }
        if ($type === 's') {
            return trim($shared[(int) $v] ?? '');
        }
        if ($type === 'b') {
            return $v === '1' ? 'TRUE' : 'FALSE';
        }
        if ($type === 'str' || $type === 'e') {
            return trim($v);
        }

        return self::number($v);
    }

    /** 72.0 -> "72", 72.5 -> "72.5", 0.1+0.2 noise is rounded away. */
    private static function number(string $v): string
    {
        $v = trim($v);
        if (!is_numeric($v)) {
            return $v;
        }
        $f = (float) $v;
        if (abs($f) < 1e15 && floor($f) == $f) {
            return (string) (int) $f;
        }

        return rtrim(rtrim(number_format($f, 8, '.', ''), '0'), '.');
    }

    private static function colIndex(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)\d+$/', strtoupper($ref), $m)) {
            return -1;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }
}
