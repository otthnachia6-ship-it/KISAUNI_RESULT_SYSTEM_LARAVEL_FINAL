<?php

namespace App\Services;

use App\Models\Examination;
use App\Models\ExamClassStatus;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads a result file exported from the government ZEMS (Ozems) system and
 * saves the scores into the marks table for ONE class and ONE examination.
 *
 * Accepted files: the Excel 97-2003 file (.xls) exactly as ZEMS exports it, or a CSV.
 * Layout (one subject per file, usually the whole standard with all its streams):
 *   - near the top: "SUBJECT CODE:" followed by the code (108.0), "SUBJECT NAME:" followed by
 *     the name (ARABIC), and optionally "YEAR: 2026"; the class label (STD7) and the term
 *     (TERM ONE) sit in the two header rows under it
 *   - then one row per pupil: ZEMS NO | first name | middle name | surname | gender | score
 *
 * Pupils are recognised by ZEMS number. A pupil whose ZEMS number is not stored yet is
 * linked by an exact, unique full-name match (with matching gender) inside the teacher's own
 * class. Pupils of other streams in the same file are skipped without complaint.
 *
 * Every user-facing problem is reported with an InvalidArgumentException whose message is safe
 * to show. The whole import runs in one database transaction, so a failure never leaves half
 * of a file saved.
 */
class OzemsImporter
{
    public const SCORE_COLUMN = 5;
    public const HEADER_SCAN_ROWS = 20;
    public const LOCKED_STATUSES = ['submitted', 'under_review', 'approved'];

    /** Turns "108.0", " 108 ", "121d" into "108", "108", "121D". */
    public static function normalizeCode(?string $raw): string
    {
        $code = trim((string) $raw);
        $code = preg_replace('/^\xEF\xBB\xBF/', '', $code);
        $code = preg_replace('/\.0+$/', '', $code);

        return strtoupper($code);
    }

    /** Turns " ze0423951897 " into "ZE0423951897"; empty becomes null. */
    public static function normalizeZems(?string $raw): ?string
    {
        $zems = strtoupper(preg_replace('/\s+/', '', (string) $raw));

        return $zems === '' ? null : $zems;
    }

    /**
     * A pupil number as ZEMS prints it: ZE0423951897, ZE20260417996 or the older
     * ZL0033/0214/21 - letters, digits, "/" and "-", at least one digit, no spaces.
     */
    public static function looksLikeZems(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9\/\-]{5,31}$/', $value)
            && (bool) preg_match('/\d/', $value);
    }

    /** "Amina  juma-Ali" -> "ALI AMINA JUMA": same pupil whatever the order of the names. */
    public static function nameKey(?string $name): string
    {
        $clean = mb_strtoupper(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $name));
        $tokens = preg_split('/\s+/u', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** Reads the file (Excel .xls or CSV) into rows of text cells. */
    public static function readRows(string $path): array
    {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new InvalidArgumentException('The file is empty or could not be read.');
        }

        if (XlsReader::isXls($content)) {
            return XlsReader::readFirstSheet($content);
        }
        if (strncmp($content, "PK\x03\x04", 4) === 0) {
            throw new InvalidArgumentException('Excel .xlsx files are not supported. Upload the .xls file exactly as exported from ZEMS (do not re-save it), or save it as CSV.');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $delimiter = static::detectDelimiter($content);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($cells = fgetcsv($stream, 0, $delimiter)) !== false) {
            $rows[] = array_map(fn($c) => trim((string) $c), $cells);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @return array{code:string,name:string,std:?int,year:?string,term:?string,rows:array<int,array<string,string>>}
     */
    public static function parse(string $path): array
    {
        $rows = static::readRows($path);

        [$headerIndex, $code, $name] = static::findHeader($rows);
        $meta = static::findMeta($rows, $headerIndex);

        $students = [];
        for ($i = $headerIndex + 1; $i < count($rows); $i++) {
            $zemsCell = $rows[$i][0] ?? '';
            if (!static::looksLikeZems($zemsCell)) {
                continue;
            }
            $nameParts = array_filter([
                $rows[$i][1] ?? '', $rows[$i][2] ?? '', $rows[$i][3] ?? '',
            ], fn($p) => $p !== '');

            $students[] = [
                'line' => $i + 1,
                'zems' => static::normalizeZems($zemsCell),
                'name' => trim(implode(' ', $nameParts)),
                'gender' => strtoupper(substr($rows[$i][4] ?? '', 0, 1)),
                'raw' => $rows[$i][static::SCORE_COLUMN] ?? '',
            ];
        }

        if (empty($students)) {
            throw new InvalidArgumentException('No pupil rows (ZEMS numbers) were found in this file.');
        }

        return ['code' => $code, 'name' => $name, 'std' => $meta['std'], 'year' => $meta['year'],
            'term' => $meta['term'], 'rows' => $students];
    }

    private static function detectDelimiter(string $content): string
    {
        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $content), 0, 12));
        $best = ',';
        $bestCount = -1;
        foreach ([',', ';', "\t"] as $d) {
            $count = substr_count($sample, $d);
            if ($count > $bestCount) {
                $best = $d;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @return array{0:int,1:string,2:string} header row index, normalized code, subject name */
    private static function findHeader(array $rows): array
    {
        $limit = min(count($rows), static::HEADER_SCAN_ROWS);
        for ($i = 0; $i < $limit; $i++) {
            $row = $rows[$i];
            $code = null;
            $name = '';

            foreach ($row as $idx => $cell) {
                if ($code === null && preg_match('/^SUBJECT\s*CODE\s*:?\s*(.*)$/i', $cell, $m)) {
                    $code = $m[1] !== '' ? $m[1] : static::nextNonEmpty($row, $idx);
                }
                if ($name === '' && preg_match('/^SUBJECT\s*NAME\s*:?\s*(.*)$/i', $cell, $m)) {
                    $name = $m[1] !== '' ? $m[1] : static::nextNonEmpty($row, $idx);
                }
            }

            if ($code !== null) {
                $code = static::normalizeCode($code);
                if (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
                    throw new InvalidArgumentException('The SUBJECT CODE in this file is empty or not valid.');
                }

                return [$i, $code, trim($name)];
            }
        }

        throw new InvalidArgumentException('Could not find the SUBJECT CODE near the top of the file. Please upload the file exactly as downloaded from ZEMS.');
    }

    /** Class label (STD7), year (2026) and term label (TERM ONE) from the header area. */
    private static function findMeta(array $rows, int $headerIndex): array
    {
        $meta = ['std' => null, 'year' => null, 'term' => null];
        $limit = min(count($rows), $headerIndex + 4);
        for ($i = 0; $i < $limit; $i++) {
            foreach ($rows[$i] as $cell) {
                if ($meta['std'] === null && preg_match('/^(?:STD|STANDARD)\s*\.?\s*0?([1-7])$/i', $cell, $m)) {
                    $meta['std'] = (int) $m[1];
                }
                if ($meta['year'] === null && preg_match('/YEAR\s*:?\s*(\d{4})/i', $cell, $m)) {
                    $meta['year'] = $m[1];
                }
                if ($meta['term'] === null && $i > $headerIndex && preg_match('/^TERM\s+\S+/i', $cell)) {
                    $meta['term'] = strtoupper($cell);
                }
            }
        }

        return $meta;
    }

    private static function nextNonEmpty(array $row, int $afterIndex): string
    {
        for ($j = $afterIndex + 1; $j < count($row); $j++) {
            if ($row[$j] !== '') {
                return $row[$j];
            }
        }

        return '';
    }

    /**
     * Validates and saves one file. Returns a summary for the screen.
     *
     * @return array<string,mixed>
     */
    public static function import($user, int $examId, int $classId, string $path): array
    {
        $parsed = static::parse($path);

        $class = SchoolClass::find($classId);
        $exam = Examination::find($examId);
        if (!$class || !$exam) {
            throw new InvalidArgumentException('The class or the examination could not be found.');
        }

        // Wrong file for this class / year? Stop before anything is saved.
        if ($parsed['std'] !== null && $class->standard !== null && (int) $parsed['std'] !== (int) $class->standard) {
            throw new InvalidArgumentException(
                "This file is for Standard {$parsed['std']}, but your class is {$class->name}. Please upload the file of your own class."
            );
        }
        if ($parsed['year'] !== null && preg_match('/\d{4}/', (string) $exam->academic_year, $ym) && $ym[0] !== $parsed['year']) {
            throw new InvalidArgumentException(
                "This file is for the year {$parsed['year']}, but the examination you chose is for {$exam->academic_year}."
            );
        }

        $subject = Subject::where('subject_code', $parsed['code'])->first();
        if (!$subject) {
            $label = $parsed['name'] !== '' ? " ({$parsed['name']})" : '';
            throw new InvalidArgumentException(
                "Subject code {$parsed['code']}{$label} is not registered in this system. Ask the Headmaster to add it under Subjects."
            );
        }

        $taughtHere = DB::table('class_subjects')
            ->where('class_id', $classId)
            ->where('subject_id', $subject->id)
            ->exists();
        if (!$taughtHere) {
            throw new InvalidArgumentException(
                "{$subject->name} (code {$parsed['code']}) is not assigned to your class. Ask the Headmaster to tick it under Subjects."
            );
        }

        $status = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
        if ($status && in_array($status->status, static::LOCKED_STATUSES, true)) {
            throw new InvalidArgumentException('Results for this class and examination are already submitted or approved and cannot be changed.');
        }

        $classStudents = Student::where('class_id', $classId)->where('active', 1)->get();
        $byZems = [];
        $unlinkedByName = [];
        foreach ($classStudents as $st) {
            $z = static::normalizeZems($st->zems_no);
            if ($z !== null) {
                $byZems[$z] = $st;
            } else {
                $unlinkedByName[static::nameKey($st->full_name)][] = $st;
            }
        }

        // how often each full name occurs in the whole file (a name used twice is never linked by name)
        $nameCountInFile = [];
        foreach ($parsed['rows'] as $row) {
            $k = static::nameKey($row['name']);
            $nameCountInFile[$k] = ($nameCountInFile[$k] ?? 0) + 1;
        }

        // pupils of the file that belong to somebody else in the system (other streams, removed pupils)
        $unknownHere = [];
        foreach ($parsed['rows'] as $row) {
            if (!isset($byZems[$row['zems']])) {
                $unknownHere[] = $row['zems'];
            }
        }
        $elsewhere = [];
        foreach (array_chunk(array_values(array_unique($unknownHere)), 400) as $part) {
            foreach (Student::whereIn('zems_no', $part)->get() as $other) {
                $elsewhere[static::normalizeZems($other->zems_no)] = $other;
            }
        }

        $summary = [
            'subject_name' => $subject->name,
            'subject_code' => $parsed['code'],
            'file_std' => $parsed['std'],
            'file_term' => $parsed['term'],
            'file_year' => $parsed['year'],
            'total_rows' => count($parsed['rows']),
            'saved' => 0,
            'blank' => 0,
            'other_class' => 0,
            'removed' => 0,
            'linked' => [],
            'invalid' => [],
            'not_found' => [],
            'duplicates' => [],
            'missing_students' => [],
        ];

        DB::transaction(function () use (
            $parsed, &$byZems, $unlinkedByName, $nameCountInFile, $elsewhere, $classStudents,
            $subject, $user, $examId, $classId, &$summary
        ) {
            $nowStr = SchoolService::nowStr();
            $seen = [];
            $savedStudentIds = [];

            foreach ($parsed['rows'] as $row) {
                $zems = $row['zems'];
                $label = $row['name'] !== '' ? "{$row['name']} ({$zems})" : $zems;

                if (isset($seen[$zems])) {
                    $summary['duplicates'][] = $label;
                    continue;
                }
                $seen[$zems] = true;

                if (!isset($byZems[$zems])) {
                    if (isset($elsewhere[$zems])) {
                        // known pupil of another class (stream) or a removed pupil: not an error
                        $elsewhere[$zems]->active ? $summary['other_class']++ : $summary['removed']++;
                        continue;
                    }

                    $linked = static::tryLinkByName($row, $unlinkedByName, $nameCountInFile);
                    if (!$linked) {
                        $summary['not_found'][] = $label;
                        continue;
                    }
                    $linked->update(['zems_no' => $zems]);
                    $byZems[$zems] = $linked;
                    $summary['linked'][] = "{$linked->full_name} -> {$zems}";
                }

                $value = str_replace(',', '.', trim($row['raw']));
                if ($value === '') {
                    $summary['blank']++;
                    continue;
                }
                if (!is_numeric($value)) {
                    $summary['invalid'][] = "{$label}: \"{$row['raw']}\" is not a number";
                    continue;
                }
                $score = (float) $value;
                if ($score < 0 || $score > 100) {
                    $summary['invalid'][] = "{$label}: {$row['raw']} (must be between 0 and 100)";
                    continue;
                }

                $student = $byZems[$zems];
                $existing = Mark::where('student_id', $student->id)
                    ->where('exam_id', $examId)
                    ->where('subject_id', $subject->id)
                    ->first();

                $payload = [
                    'score' => $score,
                    'class_id' => $classId,
                    'entered_by' => $user->id,
                    'updated_at' => $nowStr,
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    Mark::create($payload + [
                        'student_id' => $student->id,
                        'exam_id' => $examId,
                        'subject_id' => $subject->id,
                    ]);
                }

                $summary['saved']++;
                $savedStudentIds[$student->id] = true;
            }

            foreach ($classStudents as $st) {
                if (!isset($savedStudentIds[$st->id])) {
                    $summary['missing_students'][] = $st->full_name;
                }
            }

            if ($summary['saved'] > 0) {
                $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $classId)->first();
                if ($statusRow) {
                    $statusRow->update(['status' => 'draft']);
                } else {
                    ExamClassStatus::create(['exam_id' => $examId, 'class_id' => $classId, 'status' => 'draft']);
                }

                SchoolService::logAction(
                    $user,
                    'UPLOAD_OZEMS',
                    "exam={$examId} class={$classId}: {$subject->name} ({$parsed['code']}) saved={$summary['saved']}"
                    . (count($summary['linked']) ? ' linked=' . count($summary['linked']) : '')
                );
            }
        });

        return $summary;
    }

    /**
     * Finds the ONE pupil of the teacher's class (no ZEMS number yet) that this file row means:
     * same full name in any order, same gender when both are known, and the name is unique both
     * in the class and in the file. Anything less certain returns null (nothing is guessed).
     */
    public static function tryLinkByName(array $row, array $unlinkedByName, array $nameCountInFile): ?Student
    {
        $key = static::nameKey($row['name']);
        if ($key === '' || ($nameCountInFile[$key] ?? 0) !== 1) {
            return null;
        }
        $candidates = $unlinkedByName[$key] ?? [];
        if (count($candidates) !== 1) {
            return null;
        }

        $student = $candidates[0];
        $studentGender = strtoupper(substr((string) $student->gender, 0, 1));
        if (in_array($row['gender'], ['M', 'F'], true) && in_array($studentGender, ['M', 'F'], true) && $row['gender'] !== $studentGender) {
            return null;
        }

        return $student;
    }
}
