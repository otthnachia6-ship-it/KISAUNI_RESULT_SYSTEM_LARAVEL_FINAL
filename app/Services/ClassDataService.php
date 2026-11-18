<?php

namespace App\Services;

use App\Models\Examination;
use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Headmaster "Class tools": correcting or permanently removing a class's data.
 *
 * Everything is scoped to ONE class. Results of a class are the marks whose
 * marks.class_id is that class (promoted students keep the class they were in
 * when the marks were entered), so other classes, other examinations and the
 * history of students who have already moved up are never touched.
 *
 * plan() only reads and validates; execute() re-plans from the submitted
 * choices and applies the change inside one transaction, after a safety backup.
 */
class ClassDataService
{
    public const RESULTS = 'results';
    public const MOVE_EXAM = 'move_exam';
    public const STUDENTS = 'students';
    public const CLASS_KEEP = 'class_keep';
    public const CLASS_ALL = 'class_all';
    public const EDIT_MARKS = 'edit_marks';

    public static function actions(): array
    {
        return [
            self::EDIT_MARKS => 'Correct marks and approve again',
            self::RESULTS => 'Delete results of selected examinations',
            self::MOVE_EXAM => 'Move results to the correct examination',
            self::STUDENTS => 'Delete students only (class stays)',
            self::CLASS_KEEP => 'Delete class, keep students (move them to the correct class)',
            self::CLASS_ALL => 'Delete class together with its students',
        ];
    }

    public static function label($exam): string
    {
        return trim(($exam->exam_type ?? '') . ' ' . ($exam->academic_year ?? ''));
    }

    /** Examinations that hold results or a status for the class, plus student counts. */
    public static function overview(SchoolClass $class): array
    {
        $marks = DB::table('marks')->where('class_id', $class->id)
            ->select('exam_id', DB::raw('COUNT(*) as n'))->groupBy('exam_id')->pluck('n', 'exam_id');
        $status = DB::table('exam_class_status')->where('class_id', $class->id)->pluck('status', 'exam_id');

        $examIds = $marks->keys()->merge($status->keys())->unique()->values();
        $exams = Examination::whereIn('id', $examIds)->orderByDesc('id')->get()->map(fn ($e) => [
            'id' => (int) $e->id,
            'label' => self::label($e),
            'marks' => (int) ($marks[$e->id] ?? 0),
            'status' => $status[$e->id] ?? null,
        ])->all();

        return [
            'exams' => $exams,
            'all_exams' => Examination::orderByDesc('id')->get()->map(fn ($e) => ['id' => (int) $e->id, 'label' => self::label($e)])->all(),
            'active' => Student::where('class_id', $class->id)->where('active', 1)->count(),
            'former' => Student::where('class_id', $class->id)->where('active', 0)->count(),
            'others' => SchoolClass::where('id', '!=', $class->id)->orderBy('sort_order')->get(['id', 'name']),
        ];
    }

    /**
     * @return array{ok:bool, error:?string, action:string, title:string, lines:array, params:array}
     */
    public static function plan(SchoolClass $class, array $input): array
    {
        $action = (string) ($input['action'] ?? '');
        $plan = ['ok' => false, 'error' => null, 'action' => $action, 'title' => self::actions()[$action] ?? '', 'lines' => [], 'params' => ['action' => $action]];

        return match ($action) {
            self::RESULTS => self::planResults($class, $input, $plan),
            self::MOVE_EXAM => self::planMove($class, $input, $plan),
            self::STUDENTS => self::planStudents($class, $plan),
            self::CLASS_KEEP => self::planClassKeep($class, $input, $plan),
            self::CLASS_ALL => self::planClassAll($class, $plan),
            default => self::fail($plan, 'Choose what to do.'),
        };
    }

    private static function fail(array $plan, string $message): array
    {
        $plan['ok'] = false;
        $plan['error'] = $message;
        return $plan;
    }

    private static function studentIds(SchoolClass $class): array
    {
        return Student::where('class_id', $class->id)->pluck('id')->all();
    }

    private static function marksOfStudents(array $ids): int
    {
        return $ids ? (int) DB::table('marks')->whereIn('student_id', $ids)->count() : 0;
    }

    /** Results kept under this class for students who are no longer in it (history of promoted students). */
    private static function foreignHistory(SchoolClass $class): int
    {
        return (int) DB::table('marks')->where('class_id', $class->id)
            ->whereNotIn('student_id', Student::where('class_id', $class->id)->select('id'))->count();
    }

    private static function planResults(SchoolClass $class, array $in, array $plan): array
    {
        $ids = collect((array) ($in['exam_ids'] ?? []))->map(fn ($v) => (int) $v)->filter()->unique()->values();
        $known = collect(self::overview($class)['exams'])->keyBy('id');
        $ids = $ids->filter(fn ($id) => $known->has($id))->values();
        if ($ids->isEmpty()) {
            return self::fail($plan, 'Select at least one examination.');
        }
        foreach ($ids as $id) {
            $plan['lines'][] = [$known[$id]['label'], $known[$id]['marks'] . ' results'];
        }
        $plan['params']['exam_ids'] = $ids->all();
        $plan['ok'] = true;
        return $plan;
    }

    private static function planMove(SchoolClass $class, array $in, array $plan): array
    {
        $from = (int) ($in['from_exam'] ?? 0);
        $to = (int) ($in['to_exam'] ?? 0);
        $known = collect(self::overview($class)['exams'])->keyBy('id');
        $target = Examination::find($to);
        if (!$known->has($from) || !$target) {
            return self::fail($plan, 'Choose both examinations.');
        }
        if ($from === $to) {
            return self::fail($plan, 'Choose two different examinations.');
        }
        $busy = DB::table('marks')->where('class_id', $class->id)->where('exam_id', $to)->exists()
            || DB::table('exam_class_status')->where('class_id', $class->id)->where('exam_id', $to)->exists();
        if ($busy) {
            return self::fail($plan, 'This class already has results in ' . self::label($target) . '.');
        }
        $plan['lines'][] = ['From', $known[$from]['label']];
        $plan['lines'][] = ['To', self::label($target)];
        $plan['lines'][] = ['Results moved', (string) $known[$from]['marks']];
        $plan['params'] += ['from_exam' => $from, 'to_exam' => $to];
        $plan['ok'] = true;
        return $plan;
    }

    private static function planStudents(SchoolClass $class, array $plan): array
    {
        $ids = self::studentIds($class);
        if (!$ids) {
            return self::fail($plan, 'This class has no students.');
        }
        $plan['lines'][] = ['Students deleted', (string) count($ids)];
        $plan['lines'][] = ['Their results deleted', (string) self::marksOfStudents($ids)];
        $plan['ok'] = true;
        return $plan;
    }

    private static function planClassKeep(SchoolClass $class, array $in, array $plan): array
    {
        $target = SchoolClass::where('id', '!=', $class->id)->find((int) ($in['target_class'] ?? 0));
        if (!$target) {
            return self::fail($plan, 'Choose the correct class.');
        }
        if (self::foreignHistory($class) > 0) {
            return self::fail($plan, 'This class holds results of students who have already moved up. Delete those results first.');
        }
        $clash = DB::table('exam_class_status as a')
            ->join('exam_class_status as b', 'a.exam_id', '=', 'b.exam_id')
            ->where('a.class_id', $class->id)->where('b.class_id', $target->id)->exists();
        if ($clash) {
            return self::fail($plan, $target->name . ' already has results for the same examination.');
        }
        $ids = self::studentIds($class);
        $plan['lines'][] = ['Class deleted', $class->name];
        $plan['lines'][] = ['Students moved to ' . $target->name, (string) count($ids)];
        $plan['lines'][] = ['Results moved', (string) DB::table('marks')->where('class_id', $class->id)->count()];
        $plan['params']['target_class'] = $target->id;
        $plan['ok'] = true;
        return $plan;
    }

    private static function planClassAll(SchoolClass $class, array $plan): array
    {
        if (self::foreignHistory($class) > 0) {
            return self::fail($plan, 'This class holds results of students who have already moved up. Delete those results first.');
        }
        $ids = self::studentIds($class);
        $plan['lines'][] = ['Class deleted', $class->name];
        $plan['lines'][] = ['Students deleted', (string) count($ids)];
        $plan['lines'][] = ['Their results deleted', (string) self::marksOfStudents($ids)];
        $plan['ok'] = true;
        return $plan;
    }

    // ------------------------------------------------------------ correct marks

    /** True when the class has results (or a status) for this examination. */
    public static function hasExam(SchoolClass $class, int $examId): bool
    {
        return collect(self::overview($class)['exams'])->contains('id', $examId);
    }

    /**
     * The marks table of one examination of one class: subjects as columns, students as rows
     * (students now in the class, plus anyone whose marks for this examination are kept under it).
     */
    public static function marksGrid(SchoolClass $class, int $examId): array
    {
        $subjects = DB::table('subjects as sub')->join('class_subjects as cs', 'cs.subject_id', '=', 'sub.id')
            ->where('cs.class_id', $class->id)->select('sub.id', 'sub.name')->orderBy('sub.name')->get();

        $scores = [];
        foreach (DB::table('marks')->where('class_id', $class->id)->where('exam_id', $examId)->get(['student_id', 'subject_id', 'score']) as $m) {
            $scores[$m->student_id][$m->subject_id] = $m->score;
        }

        $students = Student::where(function ($q) use ($class, $scores) {
            $q->where(fn ($w) => $w->where('class_id', $class->id)->where('active', 1))->orWhereIn('id', array_keys($scores));
        })->orderByRaw('LOWER(full_name)')->get(['id', 'full_name', 'reg_no']);

        return [
            'subjects' => $subjects,
            'students' => $students,
            'scores' => $scores,
            'status' => DB::table('exam_class_status')->where('class_id', $class->id)->where('exam_id', $examId)->value('status'),
        ];
    }

    /**
     * Saves corrected marks (only cells that really changed) and approves the class's results again.
     * Nothing is saved when any entry is invalid.
     *
     * @param  array<int|string, array<int|string, mixed>>  $input  marks[student_id][subject_id]
     * @return int number of marks changed (0 = nothing saved, status untouched)
     * @throws InvalidArgumentException with a readable list of bad entries
     */
    public static function saveMarks($user, SchoolClass $class, int $examId, array $input): int
    {
        $grid = self::marksGrid($class, $examId);
        $names = $grid['students']->keyBy('id');
        $subjects = $grid['subjects']->keyBy('id');

        $changes = [];
        $bad = [];
        foreach ($grid['students'] as $st) {
            foreach ($subjects as $sid => $sub) {
                if (!isset($input[$st->id]) || !is_array($input[$st->id]) || !array_key_exists($sid, $input[$st->id])) {
                    continue;
                }
                $raw = trim((string) $input[$st->id][$sid]);
                $new = null;
                if ($raw !== '') {
                    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100) {
                        $bad[] = "{$st->full_name} - {$sub->name}: \"{$raw}\" (0 to 100)";
                        continue;
                    }
                    $new = (float) $raw;
                }
                $old = $grid['scores'][$st->id][$sid] ?? null;
                $same = ($old === null && $new === null) || ($old !== null && $new !== null && abs((float) $old - $new) < 1e-9);
                if (!$same) {
                    $changes[] = ['student' => $st->id, 'subject' => $sid, 'old' => $old, 'new' => $new, 'exists' => isset($grid['scores'][$st->id]) && array_key_exists($sid, $grid['scores'][$st->id])];
                }
            }
        }

        if ($bad) {
            throw new InvalidArgumentException(implode("\n", array_slice($bad, 0, 8)) . (count($bad) > 8 ? "\n+" . (count($bad) - 8) . ' more' : ''));
        }
        if (!$changes) {
            return 0;
        }

        self::safetyBackup('before-marks-edit');

        $now = SchoolService::nowStr();
        DB::transaction(function () use ($changes, $class, $examId, $user, $now) {
            foreach ($changes as $c) {
                $row = ['score' => $c['new'], 'class_id' => $class->id, 'entered_by' => $user->id, 'updated_at' => $now];
                if ($c['exists']) {
                    DB::table('marks')->where('student_id', $c['student'])->where('exam_id', $examId)->where('subject_id', $c['subject'])->update($row);
                } else {
                    DB::table('marks')->insert($row + ['student_id' => $c['student'], 'exam_id' => $examId, 'subject_id' => $c['subject']]);
                }
            }
            $approval = ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => $now];
            $has = DB::table('exam_class_status')->where('class_id', $class->id)->where('exam_id', $examId)->exists();
            $has
                ? DB::table('exam_class_status')->where('class_id', $class->id)->where('exam_id', $examId)->update($approval)
                : DB::table('exam_class_status')->insert($approval + ['class_id' => $class->id, 'exam_id' => $examId]);
        });

        $lines = array_map(fn ($c) => ($names[$c['student']]->full_name ?? $c['student']) . ' / ' . ($subjects[$c['subject']]->name ?? $c['subject'])
            . ': ' . ($c['old'] ?? '-') . ' -> ' . ($c['new'] ?? '-'), $changes);
        $text = implode('; ', $lines);
        if (strlen($text) > 3000) {
            $text = substr($text, 0, 3000) . '...';
        }
        SchoolService::logAction($user, 'CLASS_TOOL_EDIT_MARKS', "{$class->name} | exam={$examId} | " . count($changes) . " change(s): {$text}");

        return count($changes);
    }

    /**
     * Safety copy of the database, made before anything is changed.
     * On a real (MySQL) database a failure stops the action and says why; the in-memory test database is exempt.
     */
    public static function safetyBackup(string $label): ?string
    {
        try {
            return DatabaseBackupService::run($label, true)['file'];
        } catch (\Throwable $e) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                return null;
            }
            throw new RuntimeException('Safety backup failed, nothing was changed. Reason: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Applies a validated plan. Throws RuntimeException when nothing was changed. */
    public static function execute($user, SchoolClass $class, array $plan): void
    {
        if (empty($plan['ok'])) {
            throw new RuntimeException($plan['error'] ?? 'Nothing to do.');
        }
        self::safetyBackup('before-class-tool');

        $p = $plan['params'];
        $name = $class->name;

        DB::transaction(function () use ($class, $plan, $p) {
            switch ($plan['action']) {
                case self::RESULTS:
                    DB::table('marks')->where('class_id', $class->id)->whereIn('exam_id', $p['exam_ids'])->delete();
                    DB::table('exam_class_status')->where('class_id', $class->id)->whereIn('exam_id', $p['exam_ids'])->delete();
                    break;

                case self::MOVE_EXAM:
                    DB::table('marks')->where('class_id', $class->id)->where('exam_id', $p['from_exam'])->update(['exam_id' => $p['to_exam']]);
                    DB::table('exam_class_status')->where('class_id', $class->id)->where('exam_id', $p['from_exam'])->update(['exam_id' => $p['to_exam']]);
                    break;

                case self::STUDENTS:
                    self::deleteStudents(self::studentIds($class));
                    self::dropEmptyStatuses($class);
                    break;

                case self::CLASS_KEEP:
                    Student::where('class_id', $class->id)->update(['class_id' => $p['target_class']]);
                    DB::table('marks')->where('class_id', $class->id)->update(['class_id' => $p['target_class']]);
                    DB::table('exam_class_status')->where('class_id', $class->id)->update(['class_id' => $p['target_class']]);
                    self::dropClass($class);
                    break;

                case self::CLASS_ALL:
                    self::deleteStudents(self::studentIds($class));
                    self::dropClass($class);
                    break;
            }
        });

        SchoolService::logAction($user, 'CLASS_TOOL_' . strtoupper($plan['action']), $name . ' | ' . json_encode($p));
    }

    private static function deleteStudents(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('marks')->whereIn('student_id', $chunk)->delete();
            DB::table('students')->whereIn('id', $chunk)->delete();
        }
    }

    /** A status row with no results left would keep showing in Review & Approval. */
    private static function dropEmptyStatuses(SchoolClass $class): void
    {
        $left = DB::table('marks')->where('class_id', $class->id)->distinct()->pluck('exam_id');
        DB::table('exam_class_status')->where('class_id', $class->id)->whereNotIn('exam_id', $left)->delete();
    }

    private static function dropClass(SchoolClass $class): void
    {
        DB::table('users')->where('class_id', $class->id)->update(['class_id' => null]);
        DB::table('class_subjects')->where('class_id', $class->id)->delete();
        DB::table('exam_class_status')->where('class_id', $class->id)->delete();
        DB::table('marks')->where('class_id', $class->id)->update(['class_id' => null]);
        DB::table('classes')->where('id', $class->id)->delete();
    }
}
