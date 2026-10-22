<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Minimal reader for Excel 97-2003 files (.xls, "BIFF8") - the format the ZEMS
 * (Ozems) system exports. It needs no extra Composer package and no PHP
 * extension except mbstring.
 *
 * It reads the first visible worksheet and returns every cell as text:
 * numbers such as 108.0 become "108", 59.0 becomes "59", 72.5 stays "72.5".
 * Formatting, formulas and images are ignored on purpose.
 *
 * Every problem with the file is reported with an InvalidArgumentException
 * whose message is safe to show to the user.
 */
class XlsReader
{
    private const OLE_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const MAX_ROWS = 5000;
    private const MAX_COLS = 100;

    public static function isXls(string $bytes): bool
    {
        return strncmp($bytes, self::OLE_SIGNATURE, 8) === 0;
    }

    /**
     * @return array<int,array<int,string>> rows (0-based), each a list of cell texts
     */
    public static function readFirstSheet(string $bytes): array
    {
        $workbook = static::workbookStream($bytes);

        return static::parseWorkbook($workbook);
    }

    // ------------------------------------------------------------------ OLE2 container

    private static function u16(string $s, int $pos): int
    {
        return ord($s[$pos]) | (ord($s[$pos + 1]) << 8);
    }

    private static function u32(string $s, int $pos): int
    {
        return ord($s[$pos]) | (ord($s[$pos + 1]) << 8) | (ord($s[$pos + 2]) << 16) | (ord($s[$pos + 3]) << 24);
    }

    /** @return int[] */
    private static function sectorInts(string $data, int $sid, int $sectorSize): array
    {
        $chunk = substr($data, ($sid + 1) * $sectorSize, $sectorSize);
        if ($chunk === '' || strlen($chunk) < 4) {
            return [];
        }

        return array_values(unpack('V*', substr($chunk, 0, intdiv(strlen($chunk), 4) * 4)));
    }

    /** Follows a FAT chain and returns the joined bytes. */
    private static function readChain(string $data, array $fat, int $start, int $sectorSize): string
    {
        $out = '';
        $sid = $start;
        $guard = 0;
        while ($sid !== self::END_OF_CHAIN && $sid < 0xFFFFFFFA && $guard++ < 200000) {
            $out .= substr($data, ($sid + 1) * $sectorSize, $sectorSize);
            if (!isset($fat[$sid])) {
                break;
            }
            $sid = $fat[$sid];
        }

        return $out;
    }

    private static function workbookStream(string $d): string
    {
        if (strlen($d) < 512 || !static::isXls($d)) {
            throw new InvalidArgumentException('This is not an Excel (.xls) file.');
        }

        $sectorShift = static::u16($d, 30);
        $miniShift = static::u16($d, 32);
        if ($sectorShift < 7 || $sectorShift > 16 || $miniShift > $sectorShift) {
            throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
        }
        $sectorSize = 1 << $sectorShift;
        $miniSize = 1 << $miniShift;

        $numFat = static::u32($d, 44);
        $firstDir = static::u32($d, 48);
        $miniCutoff = static::u32($d, 56);
        $firstMiniFat = static::u32($d, 60);
        $firstDifat = static::u32($d, 68);
        $numDifat = static::u32($d, 72);

        // sectors that hold the FAT
        $fatSectors = [];
        for ($i = 0; $i < 109 && count($fatSectors) < $numFat; $i++) {
            $sid = static::u32($d, 76 + $i * 4);
            if ($sid < 0xFFFFFFFA) {
                $fatSectors[] = $sid;
            }
        }
        $difat = $firstDifat;
        for ($n = 0; $n < $numDifat && $difat < 0xFFFFFFFA; $n++) {
            $ints = static::sectorInts($d, $difat, $sectorSize);
            $per = count($ints) - 1;
            for ($i = 0; $i < $per && count($fatSectors) < $numFat; $i++) {
                if ($ints[$i] < 0xFFFFFFFA) {
                    $fatSectors[] = $ints[$i];
                }
            }
            $difat = $ints[$per] ?? self::END_OF_CHAIN;
        }

        $fat = [];
        foreach ($fatSectors as $sid) {
            foreach (static::sectorInts($d, $sid, $sectorSize) as $v) {
                $fat[] = $v;
            }
        }
        if (empty($fat)) {
            throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
        }

        // directory
        $dir = static::readChain($d, $fat, $firstDir, $sectorSize);
        $entries = [];
        for ($off = 0; $off + 128 <= strlen($dir); $off += 128) {
            $nameLen = static::u16($dir, $off + 64);
            $type = ord($dir[$off + 66]);
            if ($type === 0 || $nameLen < 2 || $nameLen > 64) {
                continue;
            }
            $name = mb_convert_encoding(substr($dir, $off, $nameLen - 2), 'UTF-8', 'UTF-16LE');
            $entries[] = [
                'name' => $name,
                'type' => $type,
                'start' => static::u32($dir, $off + 116),
                'size' => static::u32($dir, $off + 120),
            ];
        }

        $root = null;
        $book = null;
        foreach ($entries as $e) {
            if ($e['type'] === 5 && $root === null) {
                $root = $e;
            }
            if ($e['type'] === 2 && in_array($e['name'], ['Workbook', 'Book'], true) && $book === null) {
                $book = $e;
            }
        }
        if ($book === null) {
            throw new InvalidArgumentException('This Excel file has no worksheet data (it may be very old or damaged).');
        }

        if ($book['size'] >= $miniCutoff) {
            return substr(static::readChain($d, $fat, $book['start'], $sectorSize), 0, $book['size']);
        }

        // small stream, stored in the mini stream
        if ($root === null) {
            throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
        }
        $miniStream = substr(static::readChain($d, $fat, $root['start'], $sectorSize), 0, $root['size']);
        $miniFatBytes = static::readChain($d, $fat, $firstMiniFat, $sectorSize);
        $miniFat = $miniFatBytes === '' ? [] : array_values(unpack('V*', substr($miniFatBytes, 0, intdiv(strlen($miniFatBytes), 4) * 4)));

        $out = '';
        $sid = $book['start'];
        $guard = 0;
        while ($sid !== self::END_OF_CHAIN && $sid < 0xFFFFFFFA && $guard++ < 100000) {
            $out .= substr($miniStream, $sid * $miniSize, $miniSize);
            if (!isset($miniFat[$sid])) {
                break;
            }
            $sid = $miniFat[$sid];
        }

        return substr($out, 0, $book['size']);
    }

    // ------------------------------------------------------------------ BIFF8 records

    /** @return array<int,array<int,string>> */
    private static function parseWorkbook(string $wb): array
    {
        $len = strlen($wb);
        $pos = 0;
        $sstChunks = [];
        $sheetOffset = null;
        $sawBof = false;

        // ---- workbook globals
        while ($pos + 4 <= $len) {
            $id = static::u16($wb, $pos);
            $rl = static::u16($wb, $pos + 2);
            $data = substr($wb, $pos + 4, $rl);
            $pos += 4 + $rl;

            if ($id === 0x0809) { // BOF
                if (!$sawBof) {
                    $sawBof = true;
                    $version = static::u16($data, 0);
                    if ($version !== 0x0600) {
                        throw new InvalidArgumentException('This Excel file is in an old format (Excel 5/95). Please open it in Excel and save it as "Excel 97-2003 Workbook (.xls)" or as CSV, then upload it again.');
                    }
                }
            } elseif ($id === 0x00FC) { // SST
                $sstChunks = [$data];
                while ($pos + 4 <= $len && static::u16($wb, $pos) === 0x003C) { // CONTINUE
                    $crl = static::u16($wb, $pos + 2);
                    $sstChunks[] = substr($wb, $pos + 4, $crl);
                    $pos += 4 + $crl;
                }
            } elseif ($id === 0x0085) { // BOUNDSHEET
                $state = ord($data[4] ?? "\0") & 0x03;
                $type = ord($data[5] ?? "\0");
                if ($sheetOffset === null && $type === 0 && $state === 0) {
                    $sheetOffset = static::u32($data, 0);
                }
            } elseif ($id === 0x000A) { // EOF of the globals
                break;
            }
        }

        if (!$sawBof) {
            throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
        }
        if ($sheetOffset === null || $sheetOffset >= $len) {
            throw new InvalidArgumentException('No worksheet was found in this Excel file.');
        }

        $sst = empty($sstChunks) ? [] : static::parseSst($sstChunks);

        // ---- the worksheet
        $cells = [];
        $maxRow = -1;
        $maxCol = -1;
        $put = function (int $r, int $c, string $v) use (&$cells, &$maxRow, &$maxCol) {
            if ($r >= self::MAX_ROWS || $c >= self::MAX_COLS) {
                return;
            }
            $cells[$r][$c] = $v;
            $maxRow = max($maxRow, $r);
            $maxCol = max($maxCol, $c);
        };

        $pos = $sheetOffset;
        $depth = 0;
        $pendingFormula = null;
        while ($pos + 4 <= $len) {
            $id = static::u16($wb, $pos);
            $rl = static::u16($wb, $pos + 2);
            $data = substr($wb, $pos + 4, $rl);
            $pos += 4 + $rl;

            if ($id === 0x0809) {
                $depth++;
                continue;
            }
            if ($id === 0x000A) {
                $depth--;
                if ($depth <= 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1) {
                continue; // inside an embedded chart etc.
            }

            switch ($id) {
                case 0x00FD: // LABELSST
                    $idx = static::u32($data, 6);
                    $put(static::u16($data, 0), static::u16($data, 2), $sst[$idx] ?? '');
                    break;

                case 0x0203: // NUMBER
                    $put(static::u16($data, 0), static::u16($data, 2), static::numText(static::dbl(substr($data, 6, 8))));
                    break;

                case 0x027E: // RK
                    $put(static::u16($data, 0), static::u16($data, 2), static::numText(static::rk(static::u32($data, 6))));
                    break;

                case 0x00BD: // MULRK
                    $row = static::u16($data, 0);
                    $col = static::u16($data, 2);
                    $count = intdiv($rl - 6, 6);
                    for ($i = 0; $i < $count; $i++) {
                        $put($row, $col + $i, static::numText(static::rk(static::u32($data, 4 + $i * 6 + 2))));
                    }
                    break;

                case 0x0204: // LABEL
                    $cch = static::u16($data, 6);
                    $flags = ord($data[8] ?? "\0");
                    $raw = substr($data, 9, ($flags & 1) ? $cch * 2 : $cch);
                    $put(static::u16($data, 0), static::u16($data, 2), static::decode($raw, ($flags & 1) !== 0));
                    break;

                case 0x0006: // FORMULA
                    $row = static::u16($data, 0);
                    $col = static::u16($data, 2);
                    $res = substr($data, 6, 8);
                    if (static::u16($res, 6) === 0xFFFF) {
                        $pendingFormula = (ord($res[0]) === 0) ? [$row, $col] : null; // string result follows
                    } else {
                        $put($row, $col, static::numText(static::dbl($res)));
                    }
                    break;

                case 0x0207: // STRING (text result of the previous formula)
                    if ($pendingFormula !== null) {
                        $cch = static::u16($data, 0);
                        $flags = ord($data[2] ?? "\0");
                        $raw = substr($data, 3, ($flags & 1) ? $cch * 2 : $cch);
                        $put($pendingFormula[0], $pendingFormula[1], static::decode($raw, ($flags & 1) !== 0));
                        $pendingFormula = null;
                    }
                    break;
            }
        }

        $rows = [];
        for ($r = 0; $r <= $maxRow; $r++) {
            $row = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $row[] = isset($cells[$r][$c]) ? trim($cells[$r][$c]) : '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** Shared string table, with strings that continue in the next record. */
    private static function parseSst(array $chunks): array
    {
        $unique = static::u32($chunks[0], 4);
        $ci = 0;
        $cp = 8;
        $count = count($chunks);

        $read = function (int $k) use (&$ci, &$cp, $chunks, $count): string {
            $out = '';
            while ($k > 0) {
                if ($cp >= strlen($chunks[$ci])) {
                    if (++$ci >= $count) {
                        throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
                    }
                    $cp = 0;
                    continue;
                }
                $take = min($k, strlen($chunks[$ci]) - $cp);
                $out .= substr($chunks[$ci], $cp, $take);
                $cp += $take;
                $k -= $take;
            }

            return $out;
        };

        $strings = [];
        for ($s = 0; $s < $unique; $s++) {
            if ($ci >= $count - 1 && $cp >= strlen($chunks[$ci])) {
                break;
            }
            $head = $read(3);
            $cch = static::u16($head, 0);
            $flags = ord($head[2]);
            $utf16 = ($flags & 0x01) !== 0;
            $hasExt = ($flags & 0x04) !== 0;
            $hasRich = ($flags & 0x08) !== 0;
            $runs = $hasRich ? static::u16($read(2), 0) : 0;
            $extLen = $hasExt ? static::u32($read(4), 0) : 0;

            $text = '';
            $remaining = $cch;
            while ($remaining > 0) {
                $avail = strlen($chunks[$ci]) - $cp;
                if ($avail <= 0) {
                    // the string goes on in the next record, which starts with its own flags byte
                    if (++$ci >= $count) {
                        throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
                    }
                    $utf16 = (ord($chunks[$ci][0] ?? "\0") & 0x01) !== 0;
                    $cp = 1;
                    continue;
                }
                $unit = $utf16 ? 2 : 1;
                $take = min($remaining, intdiv($avail, $unit));
                if ($take === 0) {
                    throw new InvalidArgumentException('The Excel file looks damaged and could not be read.');
                }
                $text .= static::decode(substr($chunks[$ci], $cp, $take * $unit), $utf16);
                $cp += $take * $unit;
                $remaining -= $take;
            }

            $skip = $runs * 4 + $extLen;
            if ($skip > 0) {
                $read($skip);
            }
            $strings[] = $text;
        }

        return $strings;
    }

    private static function decode(string $raw, bool $utf16): string
    {
        return $utf16
            ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
            : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    private static function dbl(string $eight): float
    {
        return unpack('e', $eight)[1]; // 'e' = little-endian double, on every machine
    }

    private static function rk(int $rk): float
    {
        if ($rk & 0x02) {
            $signed = $rk > 0x7FFFFFFF ? $rk - 0x100000000 : $rk;
            $value = (float) ($signed >> 2);
        } else {
            $value = unpack('e', pack('V', 0) . pack('V', $rk & 0xFFFFFFFC))[1];
        }

        return ($rk & 0x01) ? $value / 100 : $value;
    }

    private static function numText(float $v): string
    {
        if (is_nan($v) || is_infinite($v)) {
            return '';
        }
        if (floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }

        return rtrim(rtrim(number_format($v, 10, '.', ''), '0'), '.');
    }
}
