<?php

namespace App\Http\Controllers;

use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\BackupService;
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

            SchoolClass::where('id', $classId)->update(['teacher_id' => $teacherId]);
            User::where('class_id', $classId)->update(['class_id' => null]);
            if ($teacherId) {
                User::where('id', $teacherId)->update(['class_id' => $classId]);
            }

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

        return redirect()->route('classes')->with(
            'success',
            "Class '{$name}' added successfully with its subjects already set up."
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

    public function promote(Request $request)
    {
        $user = Auth::user();
        $currentAcademicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
        $allClasses = SchoolClass::orderBy('sort_order')->get();
        $allClassesById = $allClasses->keyBy('id');
        $allClassesByName = $allClasses->keyBy('name');

        // 1. Class Teacher promotion
        if ($user->isClassTeacher()) {
            $myClass = $user->class_id ? SchoolClass::find($user->class_id) : null;
            if (!$myClass) {
                return redirect()->route('dashboard')->with(
                    'warning',
                    'You are not currently assigned to a class. Contact the Headmaster.'
                );
            }

            $studentCount = Student::where('class_id', $myClass->id)->where('active', 1)->count();
            $canPromote = SchoolService::classCanPromote($myClass, $currentAcademicYear);
            $isFinalStandard = boolval($myClass->standard && $myClass->standard >= SchoolService::NUM_STANDARDS);
            $guess = $isFinalStandard ? null : SchoolService::guessPromotionTarget($myClass, $allClassesByName);
            $otherClasses = $isFinalStandard ? [] : $allClasses->where('id', '!=', $myClass->id)->values();

            if ($request->isMethod('post')) {
                if (!$canPromote) {
                    return back()->with(
                        'danger',
                        'The Headmaster has not changed the academic year in Settings yet - please wait until a new academic year is set before promoting your class.'
                    );
                }

                $targetRaw = $request->input('target_class_id', '');

                if ($isFinalStandard || $targetRaw === 'graduate') {
                    if ($studentCount === 0) {
                        return back()->with('danger', 'Nothing to graduate - there are no students in this class.');
                    }

                    BackupService::createBackup("before_class_teacher_graduate_{$myClass->id}");

                    Student::where('class_id', $myClass->id)->where('active', 1)->update([
                        'active' => 0,
                        'leave_reason' => "Graduated / completed Standard Seven in {$currentAcademicYear}",
                    ]);

                    $myClass->update(['last_promoted_year' => $currentAcademicYear]);

                    SchoolService::logAction(
                        $user,
                        'PROMOTE_MY_CLASS_GRADUATE',
                        "class={$myClass->id} count={$studentCount} year={$currentAcademicYear}"
                    );

                    return redirect()->route('students')->with(
                        'success',
                        "{$studentCount} student(s) marked as graduated (Class of {$currentAcademicYear})."
                    );
                }

                $targetId = is_numeric($targetRaw) ? (int) $targetRaw : null;
                $target = $targetId ? ($allClassesById[$targetId] ?? null) : null;
                if (!$target || $target->id == $myClass->id || $studentCount === 0) {
                    return back()->with('danger', 'Nothing to move - please check the class you selected.');
                }

                BackupService::createBackup("before_class_teacher_promote_{$myClass->id}_to_{$target->id}");

                Student::where('class_id', $myClass->id)->where('active', 1)->update([
                    'class_id' => $target->id,
                ]);

                $myClass->update(['last_promoted_year' => $currentAcademicYear]);

                SchoolService::logAction(
                    $user,
                    'PROMOTE_MY_CLASS',
                    "{$myClass->id} -> {$target->id} count={$studentCount} year={$currentAcademicYear}"
                );

                return redirect()->route('students')->with(
                    'success',
                    "{$studentCount} student(s) moved from {$myClass->name} to {$target->name}."
                );
            }

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

        // 2. Headmaster promotion
        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $sourceClasses = [];
        foreach ($allClasses as $cls) {
            $count = Student::where('class_id', $cls->id)->where('active', 1)->count();
            if ($count > 0) {
                $guess = SchoolService::guessPromotionTarget($cls, $allClassesByName);
                $sourceClasses[] = [
                    'cls' => $cls,
                    'student_count' => $count,
                    'guess' => $guess,
                    'can_promote' => SchoolService::classCanPromote($cls, $currentAcademicYear),
                ];
            }
        }

        if ($request->isMethod('post')) {
            $newAcademicYearInput = trim($request->input('new_academic_year', ''));
            $moveTeachers = $request->input('move_teachers') === 'on';

            if ($newAcademicYearInput && $newAcademicYearInput !== $currentAcademicYear) {
                $newAcademicYear = $newAcademicYearInput;
                $advancingYearNow = true;
            } else {
                $newAcademicYear = $currentAcademicYear;
                $advancingYearNow = false;
            }

            $promotable = array_filter($sourceClasses, fn($s) => $s['can_promote']);
            if (!$advancingYearNow && empty($promotable)) {
                return back()->with(
                    'danger',
                    'Every class has already been promoted for the current academic year. Enter a new academic year above, or change it first in Settings, before promoting again.'
                );
            }

            $summary = [];
            BackupService::createBackup("before_promote_{$currentAcademicYear}_to_{$newAcademicYear}");

            foreach ($sourceClasses as $item) {
                $cls = $item['cls'];
                if (!$item['can_promote']) {
                    $summary[] = "{$cls->name}: skipped (already promoted for {$currentAcademicYear})";
                    continue;
                }

                $targetRaw = $request->input("target_{$cls->id}", '');

                if ($targetRaw === 'graduate') {
                    Student::where('class_id', $cls->id)->where('active', 1)->update([
                        'active' => 0,
                        'leave_reason' => "Graduated / completed Standard Seven in {$currentAcademicYear}",
                    ]);
                    $cls->update(['last_promoted_year' => $newAcademicYear]);
                    $summary[] = "{$cls->name}: {$item['student_count']} student(s) graduated/left school";
                    SchoolService::logAction(
                        $user,
                        'PROMOTE_GRADUATE',
                        "class={$cls->id} count={$item['student_count']} year={$currentAcademicYear}"
                    );
                    continue;
                }

                $targetId = is_numeric($targetRaw) ? (int) $targetRaw : null;
                if (!$targetId) {
                    continue;
                }

                $target = $allClassesById[$targetId] ?? null;
                if (!$target || $target->id == $cls->id) {
                    continue;
                }

                Student::where('class_id', $cls->id)->where('active', 1)->update([
                    'class_id' => $target->id,
                ]);

                $cls->update(['last_promoted_year' => $newAcademicYear]);

                if ($moveTeachers && $cls->teacher_id && !$target->teacher_id) {
                    $tid = $cls->teacher_id;
                    $target->update(['teacher_id' => $tid]);
                    User::where('id', $tid)->update(['class_id' => $target->id]);
                    $cls->update(['teacher_id' => null]);
                }

                $summary[] = "{$cls->name} -> {$target->name}: {$item['student_count']} student(s) moved";
                SchoolService::logAction(
                    $user,
                    'PROMOTE_CLASS',
                    "{$cls->id} -> {$target->id} count={$item['student_count']} year={$currentAcademicYear}"
                );
            }

            if ($advancingYearNow) {
                Setting::set('academic_year', $newAcademicYear);
                SchoolService::logAction(
                    $user,
                    'ADVANCE_ACADEMIC_YEAR',
                    "{$currentAcademicYear} -> {$newAcademicYear}"
                );
            }

            $yearNote = $advancingYearNow ? " Academic year is now {$newAcademicYear}." : '';
            return redirect()->route('classes')->with(
                'success',
                'Promotion complete: ' . implode('; ', $summary) . '.' . $yearNote
            );
        }

        return view('promote', [
            'source_classes' => $sourceClasses,
            'current_academic_year' => $currentAcademicYear,
            'all_classes' => $allClasses,
        ]);
    }
}
