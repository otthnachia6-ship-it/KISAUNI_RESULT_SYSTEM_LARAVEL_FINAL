<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ExamClassStatus;
use App\Models\Examination;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SchoolService
{
    public const DEFAULT_SCHOOL_NAME = 'KISAUNI PRIMARY SCHOOL';
    public const DEFAULT_ACADEMIC_YEAR = '2026';

    public const ROLE_HEADMASTER = 'headmaster';
    public const ROLE_CLASS_TEACHER = 'class_teacher';

    public const GRADE_BANDS = [
        ['A', 81, 100, '#198754'], // green
        ['B', 61, 80,  '#0d6efd'], // blue
        ['C', 41, 60,  '#ffc107'], // yellow/amber
        ['D', 21, 40,  '#fd7e14'], // orange
        ['E', 0,  20,  '#dc3545'], // red
    ];

    public const REMARK_LEVELS = [
        'Poor',
        'Needs Improvement',
        'Satisfactory',
        'Good',
        'Very Good',
        'Excellent',
    ];

    public const GRADE_REMARK_POINTS = [
        'A' => 5,
        'B' => 4,
        'C' => 3,
        'D' => 2,
        'E' => 1,
    ];

    public const ORDINAL_WORDS = [
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
    ];

    public const NUM_STANDARDS = 7;

    public const DEFAULT_STREAMS = [
        1 => ['A', 'B'],
        2 => ['A', 'B'],
        3 => ['A', 'B'],
        4 => ['A', 'B'],
        5 => ['A', 'B', 'C'],
        6 => ['A', 'B', 'C'],
        7 => ['A', 'B', 'C'],
    ];

    public const LOWER_CLASS_SUBJECTS = ['SUMI', 'Kiswahili', 'English', 'Mazingira', 'Dini', 'Mathematics'];

    public const UPPER_CLASS_SUBJECTS = [
        'Kiswahili', 'English', 'Mathematics', 'Dini', 'Arabic',
        'S.JAMII', 'Science and Technology', 'SUMI',
    ];

    public const LOWER_CLASSES = [1, 2, 3];

    public const EXAM_TYPES = ['MID TERM', 'FIRST TERM', 'SECOND MID TERM', 'SECOND TERM'];

    public const AUDIT_LOG_RETENTION_DAYS = 3;

    public const MALE_NAMES = [
        'mohamed', 'muhammad', 'mohammed', 'ally', 'ali', 'hassan', 'hussein',
        'husein', 'omar', 'omary', 'juma', 'jumanne', 'issa', 'iddi', 'idd',
        'rashid', 'rashidi', 'salum', 'salim', 'khamis', 'khalifa', 'abdallah',
        'abdala', 'abdulla', 'abdullah', 'yusuph', 'yusuf', 'ibrahim', 'ismail',
        'ismaili', 'said', 'seif', 'suleiman', 'sulemani', 'hamisi', 'hamis',
        'athuman', 'athumani', 'shabani', 'shaban', 'ramadhani', 'ramadhan',
        'bakari', 'bakar', 'hamad', 'hamadi', 'kassim', 'kasim', 'amiri', 'amir',
        'musa', 'moses', 'daudi', 'david', 'yohana', 'john', 'johnson', 'peter',
        'petro', 'paulo', 'paul', 'joseph', 'yosefu', 'emmanuel', 'emanueli',
        'frank', 'francis', 'fransisko', 'michael', 'mikael', 'jackson', 'james',
        'jacob', 'yakobo', 'erick', 'eric', 'godfrey', 'godwin', 'edward',
        'eduard', 'richard', 'anthony', 'antony', 'baraka', 'boniface',
        'bonifasi', 'charles', 'chalo', 'clement', 'dennis', 'denis', 'dickson',
        'elias', 'eliya', 'evans', 'fred', 'fredrick', 'gabriel', 'gasper',
        'george', 'goodluck', 'hamza', 'hussen', 'innocent', 'isaya', 'jaffar',
        'jafari', 'kelvin', 'kevin', 'khalfan', 'leonard', 'makame', 'maulid',
        'mbaraka', 'mussa', 'nasoro', 'nassoro', 'nuru', 'rajabu', 'rajab',
        'salehe', 'saleh', 'selemani', 'sharif', 'sharrif', 'vincent', 'wilbert',
        'zubery', 'zuberi', 'abel', 'adam', 'amani', 'andrew', 'andrea', 'aziz',
    ];

    public const FEMALE_NAMES = [
        'fatuma', 'fatma', 'aisha', 'aysha', 'amina', 'mwanaisha', 'mwajuma',
        'zainab', 'zainabu', 'mariam', 'maria', 'mary', 'halima', 'hadija',
        'hadijah', 'khadija', 'rehema', 'rukia', 'rukiya', 'salma', 'saumu',
        'asha', 'asya', 'bahati', 'bibi', 'farida', 'hawa', 'husna',
        'jamila', 'jamela', 'kulthum', 'latifa', 'mwanahawa',
        'mwanahamisi', 'mwanaidi', 'nasra', 'neema', 'pili', 'rahma', 'raya',
        'sabra', 'salama', 'shakira', 'sofia', 'subira', 'tatu', 'tunu',
        'upendo', 'zaituni', 'zulfa', 'agnes', 'agness', 'alice', 'anna',
        'anastazia', 'beatrice', 'catherine', 'cecilia', 'consolata',
        'dorcas', 'dorothy', 'edna', 'elizabeth', 'esther', 'eunice', 'faraja',
        'flora', 'florence', 'gladness', 'glory', 'grace', 'happiness', 'irene',
        'jane', 'janeth', 'jesca', 'joyce', 'judith', 'juliana', 'lightness',
        'lilian', 'lucy', 'magdalena', 'margareth', 'martha', 'monica',
        'naomi', 'paulina', 'prisca', 'rose', 'ruth', 'sarah', 'sara',
        'scholastica', 'stella', 'susan', 'teresia', 'veronica', 'victoria',
        'winfrida', 'yustina', 'zawadi', 'zena', 'zuhura',
    ];

    public static function now(): Carbon
    {
        return Carbon::now('Africa/Dar_es_Salaam');
    }

    public static function nowStr(): string
    {
        return static::now()->format('Y-m-d H:i:s');
    }

    public static function gradeForScore($score): array
    {
        if ($score === null || $score === '') {
            return ['-', '#6c757d'];
        }
        if (!is_numeric($score)) {
            return ['-', '#6c757d'];
        }
        $score = (float) $score;
        foreach (static::GRADE_BANDS as [$letter, $low, $high, $colour]) {
            if ($score >= $low) {
                return [$letter, $colour];
            }
        }
        return ['E', '#dc3545'];
    }

    public static function remarkFor($grade, $position, $totalStudents): string
    {
        if (!isset(static::GRADE_REMARK_POINTS[$grade]) || !is_int($position) || !$totalStudents) {
            return '-';
        }
        $points = static::GRADE_REMARK_POINTS[$grade];
        $fractionFromTop = ($position - 1) / max($totalStudents - 1, 1);
        if ($fractionFromTop <= (1.0 / 3.0)) {
            $points += 1;
        } elseif ($fractionFromTop > (2.0 / 3.0)) {
            $points -= 1;
        }
        $points = max(0, min(count(static::REMARK_LEVELS) - 1, $points));
        return static::REMARK_LEVELS[$points];
    }

    public static function subjectsForStandard($standard): array
    {
        $std = is_numeric($standard) ? (int) $standard : null;
        if ($std !== null && in_array($std, static::LOWER_CLASSES, true)) {
            return static::LOWER_CLASS_SUBJECTS;
        }
        return static::UPPER_CLASS_SUBJECTS;
    }

    public static function detectGender(?string $fullName): ?string
    {
        if (!$fullName) {
            return null;
        }
        $parts = preg_split('/\s+/', trim($fullName));
        if (empty($parts[0])) {
            return null;
        }
        $first = strtolower(str_replace('.', '', $parts[0]));
        if (in_array($first, static::MALE_NAMES, true)) {
            return 'Male';
        }
        if (in_array($first, static::FEMALE_NAMES, true)) {
            return 'Female';
        }
        return null;
    }

    public static function logAction($user, string $action, string $details = ''): void
    {
        $userId = null;
        $username = 'system';
        if ($user) {
            if (is_array($user)) {
                $userId = $user['id'] ?? null;
                $username = $user['username'] ?? 'system';
            } else {
                $userId = $user->id;
                $username = $user->username;
            }
        }

        AuditLog::create([
            'user_id' => $userId,
            'username' => $username,
            'action' => $action,
            'details' => $details,
            'created_at' => static::nowStr(),
        ]);

        static::purgeOldAuditLogs();
    }

    public static function purgeOldAuditLogs(): void
    {
        $cutoff = static::now()->subDays(static::AUDIT_LOG_RETENTION_DAYS)->format('Y-m-d H:i:s');
        AuditLog::where('created_at', '<', $cutoff)->delete();
    }

    public static function getStatusHistory($examId, $classId)
    {
        $exact = "exam={$examId} class={$classId}";
        return AuditLog::whereIn('action', ['SUBMIT_RESULTS', 'START_REVIEW', 'APPROVE_RESULTS', 'RETURN_RESULTS'])
            ->where(function ($q) use ($exact) {
                $q->where('details', $exact)
                  ->orWhere('details', 'like', $exact . ':%');
            })
            ->orderBy('id', 'desc')
            ->get();
    }

    public static function humanizeLogDetails(?string $details): ?string
    {
        if (!$details) {
            return $details;
        }
        if (preg_match('/^exam=(\d+) class=(\d+)(.*)$/', $details, $matches)) {
            $examId = (int) $matches[1];
            $classId = (int) $matches[2];
            $rest = $matches[3];

            $exam = Examination::find($examId);
            $cls = SchoolClass::find($classId);

            $examLabel = $exam ? "{$exam->exam_type} {$exam->academic_year}" : "Exam #{$examId}";
            $classLabel = $cls ? $cls->name : "Class #{$classId}";

            return "{$examLabel} - {$classLabel}{$rest}";
        }
        return $details;
    }

    public static function humanizeLogs($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $arr = is_array($r) ? $r : $r->toArray();
            $arr['details'] = static::humanizeLogDetails($arr['details'] ?? null);
            $out[] = $arr;
        }
        return $out;
    }

    public static function classCanPromote($class, $currentAcademicYear): bool
    {
        return true;
    }

    public static function guessPromotionTarget($class, $allClassesByName)
    {
        if (!$class || !$class->standard || $class->standard >= static::NUM_STANDARDS) {
            return null;
        }
        $nextWord = static::ORDINAL_WORDS[$class->standard + 1] ?? null;
        if (!$nextWord) {
            return null;
        }
        $guessName = "Standard {$nextWord} {$class->stream}";
        return $allClassesByName[$guessName] ?? null;
    }

    public static function computeClassResults($classId, $examId, $includeStudentId = null, bool $useSnapshot = true, bool $fallbackToCurrent = true): array
    {
        $subjects = DB::table('subjects as sub')
            ->join('class_subjects as cs', 'cs.subject_id', '=', 'sub.id')
            ->where('cs.class_id', $classId)
            ->select('sub.*')
            ->orderBy('sub.name')
            ->get();

        $subjectIds = $subjects->pluck('id')->toArray();

        $allMarks = DB::table('marks')
            ->where('exam_id', $examId)
            ->where('class_id', $classId)
            ->get();

        if ($useSnapshot && $allMarks->isNotEmpty()) {
            $studentIds = $allMarks->pluck('student_id')->unique()->toArray();
            if ($includeStudentId) {
                $studentIds[] = $includeStudentId;
                $studentIds = array_values(array_unique($studentIds));
            }
            sort($studentIds);

            $students = DB::table('students')
                ->whereIn('id', $studentIds)
                ->orderByRaw("CASE gender WHEN 'Male' THEN 0 WHEN 'Female' THEN 1 ELSE 2 END ASC")
                ->orderByRaw('LOWER(full_name) ASC')
                ->get();
        } elseif (!$fallbackToCurrent) {
            $students = collect();
        } else {
            $students = DB::table('students')
                ->where('class_id', $classId)
                ->where(function ($q) use ($includeStudentId) {
                    $q->where('active', 1);
                    if ($includeStudentId) {
                        $q->orWhere('id', $includeStudentId);
                    }
                })
                ->orderByRaw("CASE gender WHEN 'Male' THEN 0 WHEN 'Female' THEN 1 ELSE 2 END ASC")
                ->orderByRaw('LOWER(full_name) ASC')
                ->get();
        }

        $marksMap = [];
        foreach ($allMarks as $m) {
            $marksMap[$m->student_id][$m->subject_id] = $m->score;
        }

        $results = [];
        foreach ($students as $st) {
            $scores = $marksMap[$st->id] ?? [];
            $entered = [];
            foreach ($subjectIds as $sid) {
                if (isset($scores[$sid]) && $scores[$sid] !== null && $scores[$sid] !== '') {
                    $entered[] = (float) $scores[$sid];
                }
            }

            $total = !empty($entered) ? array_sum($entered) : 0;
            $average = !empty($entered) ? ($total / count($entered)) : null;
            [$grade, $colour] = $average !== null ? static::gradeForScore($average) : ['-', '#6c757d'];

            $results[] = [
                'student' => (array) $st,
                'scores' => $scores,
                'total' => $total,
                'average' => $average !== null ? round($average, 1) : null,
                'grade' => $grade,
                'colour' => $colour,
                'subjects_entered' => count($entered),
            ];
        }

        // Rank by average (desc); students with no marks go last
        $ranked = array_values(array_filter($results, fn($r) => $r['average'] !== null));
        usort($ranked, fn($a, $b) => $b['average'] <=> $a['average']);

        $pos = 0;
        $prevAvg = null;
        $totalRanked = count($ranked);
        foreach ($ranked as $i => &$r) {
            $oneBasedIndex = $i + 1;
            if ($r['average'] !== $prevAvg) {
                $pos = $oneBasedIndex;
                $prevAvg = $r['average'];
            }
            $r['position'] = $pos;
            $r['remark'] = static::remarkFor($r['grade'], $pos, $totalRanked);
        }
        unset($r);

        $rankedMap = [];
        foreach ($ranked as $r) {
            $rankedMap[$r['student']['id']] = $r;
        }

        foreach ($results as &$r) {
            $stId = $r['student']['id'];
            if (isset($rankedMap[$stId])) {
                $r['position'] = $rankedMap[$stId]['position'];
                $r['remark'] = $rankedMap[$stId]['remark'];
            } else {
                $r['position'] = '-';
                $r['remark'] = '-';
            }
        }
        unset($r);

        return [$subjects->toArray(), $results];
    }

    public static function computeSubjectStats($examId, $classId = null): array
    {
        $query = DB::table('marks as m')
            ->join('subjects as sub', 'sub.id', '=', 'm.subject_id')
            ->where('m.exam_id', $examId)
            ->whereNotNull('m.score')
            ->select('sub.id as subject_id', 'sub.name as subject_name', 'm.score');

        if ($classId) {
            $query->where('m.class_id', $classId);
        }

        $rows = $query->get();

        $bySubject = [];
        foreach ($rows as $row) {
            if (!isset($bySubject[$row->subject_id])) {
                $bySubject[$row->subject_id] = [
                    'name' => $row->subject_name,
                    'scores' => [],
                ];
            }
            $bySubject[$row->subject_id]['scores'][] = (float) $row->score;
        }

        $stats = [];
        foreach ($bySubject as $sid => $data) {
            $scores = $data['scores'];
            $average = round(array_sum($scores) / count($scores), 1);
            [$grade, $colour] = static::gradeForScore($average);
            $gradeCounts = array_fill_keys(['A', 'B', 'C', 'D', 'E'], 0);

            foreach ($scores as $sc) {
                [$g, ] = static::gradeForScore($sc);
                if (isset($gradeCounts[$g])) {
                    $gradeCounts[$g]++;
                }
            }

            $stats[] = [
                'subject_id' => $sid,
                'name' => $data['name'],
                'average' => $average,
                'grade' => $grade,
                'colour' => $colour,
                'grade_counts' => $gradeCounts,
                'student_count' => count($scores),
            ];
        }

        usort($stats, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $stats;
    }

    public static function rankAndSplitSubjects(array $subjectStats): array
    {
        $ranked = array_values(array_filter($subjectStats, fn($s) => $s['average'] !== null));
        usort($ranked, fn($a, $b) => $b['average'] <=> $a['average']);

        $topSubjects = array_slice($ranked, 0, 3);
        $topIds = array_column($topSubjects, 'subject_id');

        $remaining = array_values(array_filter($ranked, fn($s) => !in_array($s['subject_id'], $topIds, true)));
        usort($remaining, fn($a, $b) => $a['average'] <=> $b['average']);
        $bottomSubjects = array_slice($remaining, 0, 3);

        return [$topSubjects, $bottomSubjects];
    }

    public static function computeClassAnalytics($classId, $examId): array
    {
        [$subjects, $results] = static::computeClassResults($classId, $examId, null, true, false);

        $studentAverages = [];
        foreach ($results as $r) {
            if ($r['average'] !== null) {
                $studentAverages[] = $r['average'];
            }
        }

        $classAverage = !empty($studentAverages)
            ? round(array_sum($studentAverages) / count($studentAverages), 1)
            : null;

        [$classGrade, $classGradeColour] = $classAverage !== null
            ? static::gradeForScore($classAverage)
            : ['-', '#6c757d'];

        $subjectStats = static::computeSubjectStats($examId, $classId);
        [$topSubjects, $bottomSubjects] = static::rankAndSplitSubjects($subjectStats);

        return [
            'class_average' => $classAverage,
            'class_grade' => $classGrade,
            'class_grade_colour' => $classGradeColour,
            'student_count' => count($studentAverages),
            'subject_stats' => $subjectStats,
            'top_subjects' => $topSubjects,
            'bottom_subjects' => $bottomSubjects,
        ];
    }

    public static function computeTrend($classId = null, int $limit = 6): array
    {
        $exams = Examination::orderBy('academic_year', 'asc')->orderBy('id', 'asc')->get();
        $points = [];

        foreach ($exams as $e) {
            if ($classId) {
                $hasMarks = Mark::where('exam_id', $e->id)
                    ->where('class_id', $classId)
                    ->whereNotNull('score')
                    ->exists();
                if (!$hasMarks) {
                    continue;
                }
                $ca = static::computeClassAnalytics($classId, $e->id);
                $average = $ca['class_average'];
                $grade = $ca['class_grade'];
            } else {
                $rows = DB::table('marks')
                    ->where('exam_id', $e->id)
                    ->whereNotNull('score')
                    ->select('student_id', DB::raw('AVG(score) as avg_score'))
                    ->groupBy('student_id')
                    ->get();
                if ($rows->isEmpty()) {
                    continue;
                }
                $scores = $rows->pluck('avg_score')->toArray();
                $average = round(array_sum($scores) / count($scores), 1);
                [$grade, ] = static::gradeForScore($average);
            }

            $points[] = [
                'label' => "{$e->exam_type} {$e->academic_year}",
                'average' => $average,
                'grade' => $grade,
            ];
        }

        return array_slice($points, -$limit);
    }

    public static function computeClassRanking($examId): array
    {
        $classes = SchoolClass::orderBy('sort_order')->get();
        $entries = [];

        foreach ($classes as $cls) {
            $ca = static::computeClassAnalytics($cls->id, $examId);
            if ($ca['student_count'] > 0) {
                $entries[] = array_merge(['cls' => $cls->toArray()], $ca);
            }
        }

        usort($entries, fn($a, $b) => $b['class_average'] <=> $a['class_average']);

        $pos = 0;
        $prevAvg = null;
        foreach ($entries as $i => &$e) {
            $oneBasedIndex = $i + 1;
            if ($e['class_average'] !== $prevAvg) {
                $pos = $oneBasedIndex;
                $prevAvg = $e['class_average'];
            }
            $e['position'] = $pos;
        }
        unset($e);

        return $entries;
    }

    public static function computeSchoolAnalytics($examId): array
    {
        $perClass = static::computeClassRanking($examId);

        $studentRows = DB::table('marks')
            ->where('exam_id', $examId)
            ->whereNotNull('score')
            ->select('student_id', DB::raw('AVG(score) as avg_score'))
            ->groupBy('student_id')
            ->get();

        $scores = $studentRows->pluck('avg_score')->toArray();
        $schoolAverage = !empty($scores) ? round(array_sum($scores) / count($scores), 1) : null;
        [$schoolGrade, $schoolGradeColour] = $schoolAverage !== null
            ? static::gradeForScore($schoolAverage)
            : ['-', '#6c757d'];

        $highestAverage = !empty($perClass) ? $perClass[0]['class_average'] : null;
        $highestAverageClass = !empty($perClass) ? $perClass[0]['cls']['name'] : null;
        $lowestAverage = !empty($perClass) ? $perClass[count($perClass) - 1]['class_average'] : null;
        $lowestAverageClass = !empty($perClass) ? $perClass[count($perClass) - 1]['cls']['name'] : null;

        $subjectStats = static::computeSubjectStats($examId, null);
        [$topSubjects, $bottomSubjects] = static::rankAndSplitSubjects($subjectStats);

        return [
            'class_average' => $schoolAverage,
            'class_grade' => $schoolGrade,
            'class_grade_colour' => $schoolGradeColour,
            'student_count' => count($studentRows),
            'highest_average' => $highestAverage,
            'highest_average_class' => $highestAverageClass,
            'lowest_average' => $lowestAverage,
            'lowest_average_class' => $lowestAverageClass,
            'subject_stats' => $subjectStats,
            'top_subjects' => $topSubjects,
            'bottom_subjects' => $bottomSubjects,
            'per_class' => $perClass,
        ];
    }

    public static function loadStudentReportContext($studentId, $examId, $user): array
    {
        $student = Student::find($studentId);
        if (!$student) {
            return [null, null];
        }

        if ($user && $user->role === static::ROLE_CLASS_TEACHER && (int)$student->class_id !== (int)$user->class_id) {
            return [null, 'forbidden'];
        }

        $exam = Examination::find($examId);
        $cls = SchoolClass::find($student->class_id);
        $teacher = $cls && $cls->teacher_id ? User::find($cls->teacher_id) : null;

        [$subjects, $results] = static::computeClassResults($cls->id, $examId, $studentId);

        $myResult = null;
        foreach ($results as $r) {
            if ($r['student']['id'] == $studentId) {
                $myResult = $r;
                break;
            }
        }

        $totalStudents = count($results);
        $statusRow = ExamClassStatus::where('exam_id', $examId)->where('class_id', $cls->id)->first();

        return [[
            'exam' => $exam,
            'cls' => $cls,
            'student' => $student,
            'subjects' => $subjects,
            'result' => $myResult,
            'total_students' => $totalStudents,
            'teacher' => $teacher,
            'status_row' => $statusRow,
            'report_date' => static::now()->format('d/m/Y'),
        ], null];
    }

    /**
     * Verify a password against a legacy Werkzeug (Python/Flask) hash without
     * shelling out to an external interpreter - pure PHP only, so this works
     * on locked-down shared hosting (cPanel) where shell_exec()/exec() are
     * commonly disabled and python3/werkzeug are never installed.
     *
     * Supports Werkzeug's "pbkdf2:sha<bits>:<iterations>$<salt>$<hexhash>"
     * format, which is Werkzeug's default since version 2.0 and covers the
     * overwhelming majority of real accounts migrated from the Flask system.
     *
     * Werkzeug's "scrypt:..." format cannot be verified in pure PHP (no
     * scrypt extension on typical shared hosting) - for those hashes this
     * returns null so the caller can fall back to a safe "please reset your
     * password" path instead of silently rejecting a correct password.
     *
     * @return bool|null true = verified, false = wrong password, null = unverifiable (unsupported scheme)
     */
    public static function verifyLegacyWerkzeugHash(string $password, string $hash): ?bool
    {
        $parts = explode('$', $hash);
        if (count($parts) !== 3) {
            return null;
        }
        [$methodPart, $salt, $hexHash] = $parts;

        $methodBits = explode(':', $methodPart);
        if (count($methodBits) !== 3 || $methodBits[0] !== 'pbkdf2') {
            // e.g. "scrypt:..." - not verifiable without a scrypt extension
            return null;
        }

        $algo = strtolower($methodBits[1]); // sha256 / sha1 / sha512
        if (!in_array($algo, hash_algos(), true)) {
            return null;
        }
        $iterations = (int) $methodBits[2];
        if ($iterations <= 0 || $salt === '' || $hexHash === '') {
            return null;
        }

        $hexLength = strlen($hexHash);
        $computed = hash_pbkdf2($algo, $password, $salt, $iterations, $hexLength, false);

        return hash_equals(strtolower($hexHash), strtolower($computed));
    }
}
