<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Registers pupils from a ZEMS result file (which lists every pupil of the whole standard)
 * into ONE class, so nobody has to type them one by one.
 *
 *  - a pupil whose ZEMS number is already in the system is left alone
 *  - an existing pupil of the class without a ZEMS number is linked by an exact, unique
 *    full-name match (same rules as the result upload) - never duplicated
 *  - every other pupil is offered for registration; the user ticks the ones of this class,
 *    because ZEMS does not know the streams (A, B, C)
 */
class ZemsStudentImporter
{
    /**
     * @param array<int,array<string,string>> $rows rows from OzemsImporter::parse()
     * @return array{total:int,already_here:int,already_elsewhere:int,to_link:array,candidates:array}
     */
    public static function analyse(array $rows, int $classId): array
    {
        $zemsList = array_values(array_unique(array_column($rows, 'zems')));
        $known = [];
        foreach (array_chunk($zemsList, 400) as $part) {
            foreach (Student::whereIn('zems_no', $part)->get() as $s) {
                $known[OzemsImporter::normalizeZems($s->zems_no)] = $s;
            }
        }

        $unlinked = [];
        $classPupils = Student::where('class_id', $classId)->where('active', 1)
            ->where(fn($q) => $q->whereNull('zems_no')->orWhere('zems_no', ''))
            ->get();
        foreach ($classPupils as $s) {
            $unlinked[OzemsImporter::nameKey($s->full_name)][] = $s;
        }

        $nameCount = [];
        foreach ($rows as $row) {
            $k = OzemsImporter::nameKey($row['name']);
            $nameCount[$k] = ($nameCount[$k] ?? 0) + 1;
        }

        $out = ['total' => count($rows), 'already_here' => 0, 'already_elsewhere' => 0, 'to_link' => [], 'candidates' => []];
        $seen = [];
        foreach ($rows as $row) {
            $zems = $row['zems'];
            if (isset($seen[$zems])) {
                continue;
            }
            $seen[$zems] = true;

            if (isset($known[$zems])) {
                $s = $known[$zems];
                ($s->active && (int) $s->class_id === $classId) ? $out['already_here']++ : $out['already_elsewhere']++;
                continue;
            }

            $link = OzemsImporter::tryLinkByName($row, $unlinked, $nameCount);
            if ($link) {
                $out['to_link'][] = ['student_id' => $link->id, 'name' => $link->full_name, 'zems' => $zems];
                $unlinked[OzemsImporter::nameKey($row['name'])] = [];
                continue;
            }

            if ($row['name'] === '') {
                continue; // no name in the file: cannot be registered safely
            }
            $out['candidates'][] = ['zems' => $zems, 'name' => $row['name'], 'gender' => $row['gender']];
        }

        // same order as the Students page: boys first, then girls, each by name
        $rank = fn($g) => $g === 'M' ? 0 : ($g === 'F' ? 1 : 2);
        usort($out['candidates'], fn($a, $b) => [$rank($a['gender']), $a['name']] <=> [$rank($b['gender']), $b['name']]);

        return $out;
    }

    /**
     * Saves the chosen pupils (and the safe name links) in one transaction.
     *
     * @param string[] $selected ZEMS numbers ticked by the user
     * @return array{registered:int,linked:int,conflicts:string[]}
     */
    public static function commit($user, SchoolClass $class, array $rows, array $selected): array
    {
        $selectedSet = array_flip($selected);
        $result = ['registered' => 0, 'linked' => 0, 'conflicts' => []];

        DB::transaction(function () use ($user, $class, $rows, $selectedSet, &$result) {
            $analysis = static::analyse($rows, (int) $class->id);

            foreach ($analysis['to_link'] as $link) {
                if (Student::where('zems_no', $link['zems'])->exists()) {
                    continue;
                }
                Student::where('id', $link['student_id'])->update(['zems_no' => $link['zems']]);
                $result['linked']++;
            }

            $now = SchoolService::nowStr();
            foreach ($analysis['candidates'] as $c) {
                if (!isset($selectedSet[$c['zems']])) {
                    continue;
                }
                $taken = Student::where('zems_no', $c['zems'])->orWhere('reg_no', $c['zems'])->exists();
                if ($taken) {
                    $result['conflicts'][] = "{$c['name']} ({$c['zems']})";
                    continue;
                }

                $known = in_array($c['gender'], ['M', 'F'], true);
                $gender = $c['gender'] === 'M' ? 'Male' : ($c['gender'] === 'F' ? 'Female' : (SchoolService::detectGender($c['name']) ?: 'Unknown'));

                Student::create([
                    'reg_no' => $c['zems'],          // the ZEMS number until the school gives its own Reg No (Edit)
                    'zems_no' => $c['zems'],
                    'full_name' => $c['name'],
                    'gender' => $gender,
                    'gender_confirmed' => $known ? 1 : 0,
                    'class_id' => $class->id,
                    'active' => 1,
                    'created_at' => $now,
                ]);
                $result['registered']++;
            }

            if ($result['registered'] > 0 || $result['linked'] > 0) {
                SchoolService::logAction(
                    $user,
                    'IMPORT_STUDENTS',
                    "class={$class->name}: registered={$result['registered']} linked={$result['linked']}"
                );
            }
        });

        return $result;
    }
}
