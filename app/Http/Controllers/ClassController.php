<?php

namespace App\Http\Controllers;

use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\DatabaseBackupService;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($request->isMethod('post')) {
            if (!$user->isHeadmaster()) {
                abort(403);
            }

            $classId = $request->input('class_id');
            $teacherId = $request->input('teacher_id') ?: null;

            if (!$classId || !SchoolClass::whereKey($classId)->exists()) {
                return redirect()->route('classes')->with('danger', 'That class no longer exists.');
            }
            if ($teacherId && !User::whereKey($teacherId)->where('role', SchoolService::ROLE_CLASS_TEACHER)->where('active', 1)->exists()) {
                return redirect()->route('classes')->with('danger', 'Only an active Class Teacher account can be assigned to a class.');
            }

            $this->assignTeacher($classId, $teacherId);

            SchoolService::logAction(
                $user,
                'ASSIGN_CLASS_TEACHER',
                "class_id={$classId} teacher_id=" . ($teacherId ?? 'null')
            );

            return redirect()->route('classes')->with('success', 'Class Teacher assigned to class successfully.');
        }

        $classes = DB::table('classes as c')
            ->leftJoin('users as u', 'c.teacher_id', '=', 'u.id')
            ->select('c.*', 'u.full_name as teacher_name', DB::raw('(SELECT COUNT(*) FROM students s WHERE s.class_id=c.id AND s.active=1) as student_count'))
            ->orderBy('c.sort_order')
            ->get();

        $teachers = User::where('role', SchoolService::ROLE_CLASS_TEACHER)
            ->where('active', 1)
            ->orderBy('full_name')
            ->get();

        return view('classes', [
            'classes' => $classes,
            'teachers' => $teachers,
            'ordinal_words' => SchoolService::ORDINAL_WORDS,
            'num_standards' => SchoolService::NUM_STANDARDS,
        ]);
    }

    /** Sets (or clears, when $teacherId is null) the class teacher of a class and keeps both sides in step. */
    private function assignTeacher($classId, $teacherId): void
    {
        SchoolService::assignClassTeacher((int) $classId, $teacherId ? (int) $teacherId : null);
    }

    public function add(Request $request)
    {
        $user = Auth::user();
        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $standard = is_numeric($request->input('standard')) ? (int) $request->input('standard') : null;
        $stream = strtoupper(trim($request->input('stream', '')));

        if (!$standard || $standard < 1 || $standard > SchoolService::NUM_STANDARDS || !$stream) {
            return redirect()->route('classes')->with(
                'danger',
                'Please choose a valid Standard and give the new Stream a letter/name (e.g. C).'
            );
        }

        $word = SchoolService::ORDINAL_WORDS[$standard] ?? '';
        $name = "Standard {$word} {$stream}";

        if (SchoolClass::where('name', $name)->exists()) {
            return redirect()->route('classes')->with('warning', "'{$name}' already exists.");
        }

        $maxSort = SchoolClass::max('sort_order') ?? 0;
        $class = SchoolClass::create([
            'name' => $name,
            'standard' => $standard,
            'stream' => $stream,
            'sort_order' => $maxSort + 1,
            'teacher_id' => null,
            'last_promoted_year' => Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR),
        ]);

        $subjectNames = SchoolService::subjectsForStandard($standard);
        foreach ($subjectNames as $sname) {
            $s = Subject::firstOrCreate(['name' => $sname]);
            DB::table('class_subjects')->insertOrIgnore([
                'class_id' => $class->id,
                'subject_id' => $s->id,
            ]);
        }

        SchoolService::logAction($user, 'ADD_CLASS', $name);

        // Optional: the new class can be given its class teacher straight away.
        $teacherNote = '';
        $chosenTeacher = $request->input('teacher_id');
        if ($chosenTeacher) {
            $teacher = User::where('id', $chosenTeacher)
                ->where('role', SchoolService::ROLE_CLASS_TEACHER)
                ->where('active', 1)
                ->first();
            if ($teacher) {
                $this->assignTeacher($class->id, $teacher->id);
                SchoolService::logAction($user, 'ASSIGN_CLASS_TEACHER', "class_id={$class->id} teacher_id={$teacher->id}");
                $teacherNote = " {$teacher->full_name} is its class teacher.";
            } else {
                $teacherNote = ' The chosen teacher could not be assigned (not an active class teacher); you can assign one later.';
            }
        }

        return redirect()->route('classes')->with(
            'success',
            "Class '{$name}' added successfully with its subjects already set up." . $teacherNote
        );
    }

    public function delete($id)
    {
        $user = Auth::user();
        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $cls = SchoolClass::find($id);
        if (!$cls) {
            abort(404);
        }

        $activeStudents = Student::where('class_id', $id)->where('active', 1)->count();
        if ($activeStudents > 0) {
            return redirect()->route('classes')->with(
                'danger',
                "Cannot remove '{$cls->name}' - it still has {$activeStudents} student(s) in it. Move or remove them first."
            );
        }

        $hasMarks = DB::table('marks as m')
            ->join('students as s', 'm.student_id', '=', 's.id')
            ->where('s.class_id', $id)
            ->exists();
        if ($hasMarks) {
            return redirect()->route('classes')->with(
                'danger',
                "Cannot remove '{$cls->name}' - historical marks exist for students who were in this class. It will stay in the system to protect that data."
            );
        }

        $formerStudents = Student::where('class_id', $id)->where('active', 0)->count();
        if ($formerStudents > 0) {
            return redirect()->route('classes')->with(
                'danger',
                "Cannot remove '{$cls->name}' - {$formerStudents} former student(s) who used to be in this class are still on record here (to protect their history). It will stay in the system."
            );
        }

        User::where('class_id', $id)->update(['class_id' => null]);
        DB::table('class_subjects')->where('class_id', $id)->delete();
        $cls->delete();

        SchoolService::logAction($user, 'DELETE_CLASS', $cls->name);

        return redirect()->route('classes')->with('info', "Class '{$cls->name}' removed successfully.");
    }

    /**
     * Year-end promotion. Rules (both for the Headmaster and for a Class Teacher):
     *  - a class is promoted at most ONCE per academic year (SchoolService::classCanPromote);
     *  - "Graduate" is only for the final standard;
     *  - every move happens inside one database transaction: all of it, or nothing;
     *  - a safety backup is attempted first; if it fails the promotion still runs, but the
     *    person is told clearly and the failure is written to the audit log.
     */
    public function promote(Request $request)
    {
        $user = Auth::user();
        $currentAcademicYear = (string) Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $allClasses = SchoolClass::orderBy('sort_order')->get();
        $allClassesById = $allClasses->keyBy('id');
        $allClassesByName = $allClasses->keyBy('name');

        if ($user->isClassTeacher()) {
            return $this->promoteMyClass($request, $user, $currentAcademicYear, $allClasses, $allClassesById, $allClassesByName);
        }

        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $counts = Student::where('active', 1)->select('class_id', DB::raw('COUNT(*) as n'))->groupBy('class_id')->pluck('n', 'class_id');
        $sourceClasses = [];
        foreach ($allClasses as $cls) {
            $count = (int) ($counts[$cls->id] ?? 0);
            if ($count > 0) {
                $sourceClasses[] = [
                    'cls' => $cls,
                    'student_count' => $count,
                    'guess' => SchoolService::guessPromotionTarget($cls, $allClassesByName),
                    'can_promote' => SchoolService::classCanPromote($cls, $currentAcademicYear),
                ];
            }
        }

        if (!$request->isMethod('post')) {
            return view('promote', [
                'source_classes' => $sourceClasses,
                'current_academic_year' => $currentAcademicYear,
                'all_classes' => $allClasses,
            ]);
        }

        $newYearInput = trim((string) $request->input('new_academic_year', ''));
        $advancingYearNow = $newYearInput !== '' && $newYearInput !== $currentAcademicYear;
        if ($advancingYearNow && !preg_match('/^\d{4}([\/-]\d{4})?$/', $newYearInput)) {
            return back()->with('danger', 'New academic year must be a year such as 2027 (or 2027/2028).');
        }
        // The year the pupils are promoted INTO.
        $targetYear = $advancingYearNow ? $newYearInput : $currentAcademicYear;

        // Work out the whole plan first; nothing is changed until it is known to be valid.
        $plan = [];
        $summary = [];
        foreach ($sourceClasses as $item) {
            $cls = $item['cls'];
            $targetRaw = (string) $request->input("target_{$cls->id}", '');
            if ($targetRaw === '') {
                continue;
            }
            if (!SchoolService::classCanPromote($cls, $targetYear)) {
                $summary[] = "{$cls->name}: skipped (already promoted for {$targetYear})";
                continue;
            }
            if ($targetRaw === 'graduate') {
                if (!$this->canGraduate($cls)) {
                    $summary[] = "{$cls->name}: skipped (only the final standard can graduate)";
                    continue;
                }
                $plan[] = ['cls' => $cls, 'target' => null, 'count' => $item['student_count']];
                continue;
            }
            $target = is_numeric($targetRaw) ? ($allClassesById[(int) $targetRaw] ?? null) : null;
            if ($target && $target->id != $cls->id) {
                $plan[] = ['cls' => $cls, 'target' => $target, 'count' => $item['student_count']];
            }
        }

        if (empty($plan) && !$advancingYearNow) {
            $why = $summary ? ' ' . implode('; ', $summary) . '.' : '';
            return back()->with('danger', 'Nothing was promoted. Every class you chose has already been promoted for the current academic year - enter a new academic year above to promote again.' . $why);
        }

        $backupNote = $this->safetyBackupOrWarn($user, "before_promote_{$currentAcademicYear}_to_{$targetYear}");

        // Pupils of every source class are read BEFORE any move, so pupils moved into a class in
        // this step are never moved a second time by the same step.
        $idsByClass = [];
        foreach ($plan as $p) {
            $idsByClass[$p['cls']->id] = Student::where('class_id', $p['cls']->id)->where('active', 1)->pluck('id')->all();
        }

        DB::transaction(function () use ($plan, $idsByClass, $targetYear, $currentAcademicYear, $advancingYearNow, $user, &$summary) {
            foreach ($plan as $p) {
                $cls = $p['cls'];
                $ids = $idsByClass[$cls->id];
                foreach (array_chunk($ids, 500) as $chunk) {
                    $p['target']
                        ? Student::whereIn('id', $chunk)->update(['class_id' => $p['target']->id])
                        : Student::whereIn('id', $chunk)->update([
                            'active' => 0,
                            'leave_reason' => "Graduated / completed Standard Seven in {$currentAcademicYear}",
                        ]);
                }
                $cls->update(['last_promoted_year' => $targetYear]);

                if ($p['target']) {
                    // Only the students are promoted; class teachers stay (the Headmaster assigns them on the Classes page).
                    $summary[] = "{$cls->name} -> {$p['target']->name}: " . count($ids) . ' student(s) moved';
                    SchoolService::logAction($user, 'PROMOTE_CLASS', "{$cls->id} -> {$p['target']->id} count=" . count($ids) . " year={$targetYear}");
                } else {
                    $summary[] = "{$cls->name}: " . count($ids) . ' student(s) graduated/left school';
                    SchoolService::logAction($user, 'PROMOTE_GRADUATE', "class={$cls->id} count=" . count($ids) . " year={$currentAcademicYear}");
                }
            }

            if ($advancingYearNow) {
                Setting::set('academic_year', $targetYear);
                SchoolService::logAction($user, 'ADVANCE_ACADEMIC_YEAR', "{$currentAcademicYear} -> {$targetYear}");
            }
        });

        $yearNote = $advancingYearNow ? " Academic year is now {$targetYear}." : '';
        $redirect = redirect()->route('classes')->with(
            'success',
            'Promotion complete: ' . ($summary ? implode('; ', $summary) : 'no class was moved') . '.' . $yearNote
        );

        return $backupNote ? $redirect->with('warning', $backupNote) : $redirect;
    }

    /** Class Teacher: promote (or graduate) their own class only. */
    private function promoteMyClass(Request $request, User $user, string $currentAcademicYear, $allClasses, $allClassesById, $allClassesByName)
    {
        $myClass = $user->class_id ? SchoolClass::find($user->class_id) : null;
        if (!$myClass) {
            return redirect()->route('dashboard')->with('warning', 'You are not currently assigned to a class. Contact the Headmaster.');
        }

        $studentCount = Student::where('class_id', $myClass->id)->where('active', 1)->count();
        $canPromote = SchoolService::classCanPromote($myClass, $currentAcademicYear);
        $isFinalStandard = $this->canGraduate($myClass);
        $guess = $isFinalStandard ? null : SchoolService::guessPromotionTarget($myClass, $allClassesByName);
        $otherClasses = $isFinalStandard ? [] : $allClasses->where('id', '!=', $myClass->id)->values();

        if (!$request->isMethod('post')) {
            return view('promote_my_class', [
                'my_class' => $myClass,
                'student_count' => $studentCount,
                'other_classes' => $otherClasses,
                'guess' => $guess,
                'current_academic_year' => $currentAcademicYear,
                'can_promote' => $canPromote,
                'is_final_standard' => $isFinalStandard,
            ]);
        }

        if (!$canPromote) {
            return back()->with('danger', "{$myClass->name} has already been promoted for {$currentAcademicYear}. A class can be promoted once per academic year - the Headmaster changes the year in Settings when the new year starts.");
        }
        if ($studentCount === 0) {
            return back()->with('danger', 'Nothing to move - there are no students in this class.');
        }

        $targetRaw = (string) $request->input('target_class_id', '');
        $target = null;
        if ($targetRaw === 'graduate' || $isFinalStandard) {
            if (!$isFinalStandard) {
                return back()->with('danger', 'Only the final standard can graduate. Choose the class your students move up to.');
            }
        } else {
            $target = is_numeric($targetRaw) ? ($allClassesById[(int) $targetRaw] ?? null) : null;
            if (!$target || $target->id == $myClass->id) {
                return back()->with('danger', 'Nothing to move - please check the class you selected.');
            }
            // Pupils must never be mixed: the receiving class must first send its own pupils up
            // (or be empty). Otherwise the next promotion of that class would move both groups.
            $targetHasPupils = Student::where('class_id', $target->id)->where('active', 1)->exists();
            if ($targetHasPupils && SchoolService::classCanPromote($target, $currentAcademicYear)) {
                return back()->with('danger', "{$target->name} still has its own students who have not been promoted for {$currentAcademicYear}. Ask its class teacher (or the Headmaster) to promote {$target->name} first, then try again.");
            }
        }

        $backupNote = $this->safetyBackupOrWarn($user, $target
            ? "before_class_teacher_promote_{$myClass->id}_to_{$target->id}"
            : "before_class_teacher_graduate_{$myClass->id}");

        $ids = Student::where('class_id', $myClass->id)->where('active', 1)->pluck('id')->all();
        DB::transaction(function () use ($ids, $target, $myClass, $currentAcademicYear, $user) {
            foreach (array_chunk($ids, 500) as $chunk) {
                $target
                    ? Student::whereIn('id', $chunk)->update(['class_id' => $target->id])
                    : Student::whereIn('id', $chunk)->update([
                        'active' => 0,
                        'leave_reason' => "Graduated / completed Standard Seven in {$currentAcademicYear}",
                    ]);
            }
            $myClass->update(['last_promoted_year' => $currentAcademicYear]);

            $target
                ? SchoolService::logAction($user, 'PROMOTE_MY_CLASS', "{$myClass->id} -> {$target->id} count=" . count($ids) . " year={$currentAcademicYear}")
                : SchoolService::logAction($user, 'PROMOTE_MY_CLASS_GRADUATE', "class={$myClass->id} count=" . count($ids) . " year={$currentAcademicYear}");
        });

        $message = $target
            ? count($ids) . " student(s) moved from {$myClass->name} to {$target->name}."
            : count($ids) . " student(s) marked as graduated (Class of {$currentAcademicYear}).";
        $redirect = redirect()->route('students')->with('success', $message);

        return $backupNote ? $redirect->with('warning', $backupNote) : $redirect;
    }

    /** Graduation is only for the final standard (or a class without a standard number). */
    private function canGraduate(SchoolClass $cls): bool
    {
        return !$cls->standard || (int) $cls->standard >= SchoolService::NUM_STANDARDS;
    }

    /**
     * Tries the safety backup. Returns null when it worked, or a warning to show when it failed
     * (the failure is also written to the audit log). The promotion itself still goes ahead.
     */
    private function safetyBackupOrWarn(User $user, string $label): ?string
    {
        if (DatabaseBackupService::safetyBackup($label) !== null || DB::connection()->getDriverName() === 'sqlite') {
            return null;
        }

        SchoolService::logAction($user, 'BACKUP_FAILED', "Safety backup before promotion failed ({$label}); promotion continued");

        return 'Note: the automatic safety backup could not be made before this promotion, so there is no backup copy of the data from just before it. Ask the system administrator to check the backup settings.';
    }
}
