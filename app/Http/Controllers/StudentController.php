<?php

namespace App\Http\Controllers;

use App\Models\Examination;
use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $classFilter = $request->input('class_id', '');
        $search = trim($request->input('q', ''));
        $showRemoved = $user->hasHeadmasterAccess() && $request->input('status') === 'removed';

        $query = DB::table('students as s')
            ->join('classes as c', 's.class_id', '=', 'c.id')
            ->select('s.*', 'c.name as class_name', 'c.sort_order')
            ->where('s.active', $showRemoved ? 0 : 1);

        if ($user->isClassTeacher()) {
            $query->where('s.class_id', $user->class_id);
        } elseif ($classFilter) {
            $query->where('s.class_id', $classFilter);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('s.full_name', 'like', "%{$search}%")
                  ->orWhere('s.reg_no', 'like', "%{$search}%");
            });
        }

        $query->orderBy('c.sort_order', 'asc')
            ->orderByRaw("CASE s.gender WHEN 'Male' THEN 0 WHEN 'Female' THEN 1 ELSE 2 END ASC")
            ->orderByRaw('LOWER(s.full_name) ASC');

        $rows = $query->get();

        $classes = SchoolClass::orderBy('sort_order')->get();

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r->class_name][] = $r;
        }

        return view('students', [
            'grouped' => $grouped,
            'classes' => $classes,
            'class_filter' => $classFilter,
            'search' => $search,
            'show_removed' => $showRemoved,
        ]);
    }

    public function showAdd()
    {
        $user = Auth::user();
        if ($user->isClassTeacher()) {
            $classes = SchoolClass::where('id', $user->class_id)->get();
        } else {
            $classes = SchoolClass::orderBy('sort_order')->get();
        }

        return view('student_form', [
            'classes' => $classes,
            'student' => null,
            'mode' => 'add',
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showAdd();
        }

        $user = Auth::user();
        $regNo = trim((string) $request->input('reg_no', ''));
        $fullName = trim($request->input('full_name', ''));
        $classId = $request->input('class_id');
        $gender = trim($request->input('gender', ''));
        $genderConfirmed = $request->input('gender_confirmed') === 'on' ? 1 : 0;

        $error = null;
        if (!$regNo || !$fullName || !$classId) {
            $error = 'Please fill in Reg No, Full Name and Class.';
        } elseif ($regError = $this->regNoError($regNo)) {
            $error = $regError;
        } elseif (Student::where('reg_no', $regNo)->exists()) {
            $error = 'This Reg No is already registered in the system.';
        } elseif ($user->isClassTeacher() && (string)$classId !== (string)$user->class_id) {
            $error = 'You can only register students for your own assigned class.';
        } elseif (!SchoolClass::whereKey($classId)->exists()) {
            $error = 'Please choose a valid Class.';
        } elseif (mb_strlen($fullName) > 191) {
            $error = 'Full Name is too long.';
        }

        if ($error) {
            $classes = $user->isClassTeacher()
                ? SchoolClass::where('id', $user->class_id)->get()
                : SchoolClass::orderBy('sort_order')->get();

            return back()->withInput()->with('danger', $error);
        }

        if (!$gender) {
            $gender = SchoolService::detectGender($fullName) ?: 'Unknown';
        }

        Student::create([
            'reg_no' => $regNo,
            'full_name' => $fullName,
            'gender' => $gender,
            'gender_confirmed' => $genderConfirmed,
            'class_id' => $classId,
            'active' => 1,
            'created_at' => SchoolService::nowStr(),
        ]);

        SchoolService::logAction($user, 'ADD_STUDENT', "{$fullName} ({$regNo})");

        return redirect()->route('students')->with('success', "Student {$fullName} registered successfully.");
    }

    public function showAddBulk()
    {
        $user = Auth::user();
        $classes = $user->isClassTeacher()
            ? SchoolClass::where('id', $user->class_id)->get()
            : SchoolClass::orderBy('sort_order')->get();

        return view('student_form_bulk', [
            'classes' => $classes,
            'rows' => [],
            'selected_class' => null,
        ]);
    }

    public function addBulk(Request $request)
    {
        if ($request->isMethod('GET')) {
            return $this->showAddBulk();
        }

        $user = Auth::user();
        $classId = $request->input('class_id');
        $regNos = $request->input('reg_no', []);
        $fullNames = $request->input('full_name', []);
        $genders = $request->input('gender', []);

        $classes = $user->isClassTeacher()
            ? SchoolClass::where('id', $user->class_id)->get()
            : SchoolClass::orderBy('sort_order')->get();

        if ($user->isClassTeacher() && (string)$classId !== (string)$user->class_id) {
            return back()->with('danger', 'You can only register students for your own assigned class.');
        }

        if (!$classId) {
            return back()->with('danger', 'Please select a Class before saving.');
        }
        if (!SchoolClass::whereKey($classId)->exists()) {
            return back()->with('danger', 'Please choose a valid Class.');
        }

        $regNos = is_array($regNos) ? $regNos : [];
        $fullNames = is_array($fullNames) ? $fullNames : [];
        $genders = is_array($genders) ? $genders : [];

        $rowsOut = [];
        $toInsert = [];
        $seenRegNos = [];
        $errors = [];

        // Which of the typed Reg Nos already exist - ONE query instead of one per row.
        $typed = array_values(array_filter(array_map(fn ($r) => trim((string) $r), $regNos), fn ($r) => $r !== ''));
        $alreadyRegistered = [];
        foreach (array_chunk($typed, 500) as $chunk) {
            foreach (Student::whereIn('reg_no', $chunk)->pluck('reg_no') as $taken) {
                $alreadyRegistered[(string) $taken] = true;
            }
        }

        $count = max(count($regNos), count($fullNames), count($genders));
        for ($i = 0; $i < $count; $i++) {
            $rowNum = $i + 1;
            $regNo = trim((string) ($regNos[$i] ?? ''));
            $fullName = trim($fullNames[$i] ?? '');
            $gender = trim($genders[$i] ?? '');

            $rowsOut[] = ['reg_no' => $regNo, 'full_name' => $fullName, 'gender' => $gender];

            if ($regNo === '' && $fullName === '') {
                continue;
            }

            if ($regNo === '' || $fullName === '' || $gender === '') {
                $errors[] = "Row {$rowNum}: Reg No, Full Name and Gender are all required.";
                continue;
            }

            // (Messages are plain text: the page escapes them when it shows them.)
            if ($regError = $this->regNoError($regNo)) {
                $errors[] = "Row {$rowNum}: {$regError}";
                continue;
            }

            if (mb_strlen($fullName) > 191) {
                $errors[] = "Row {$rowNum}: Full Name is too long.";
                continue;
            }

            if (in_array($regNo, $seenRegNos, true)) {
                $errors[] = "Row {$rowNum}: Reg No '{$regNo}' is duplicated in this list.";
                continue;
            }

            if (isset($alreadyRegistered[$regNo])) {
                $errors[] = "Row {$rowNum}: Reg No '{$regNo}' is already registered in the system.";
                continue;
            }

            $seenRegNos[] = $regNo;
            $toInsert[] = [$regNo, $fullName, $gender];
        }

        if (empty($toInsert) && empty($errors)) {
            return back()->with('warning', 'Please add at least one student before saving.');
        }

        $nowStr = SchoolService::nowStr();
        DB::transaction(function () use ($toInsert, $classId, $nowStr) {
            foreach ($toInsert as [$regNo, $fullName, $gender]) {
                Student::create([
                    'reg_no' => $regNo,
                    'full_name' => $fullName,
                    'gender' => $gender,
                    'gender_confirmed' => 1,
                    'class_id' => $classId,
                    'active' => 1,
                    'created_at' => $nowStr,
                ]);
            }
        });

        if (!empty($toInsert)) {
            SchoolService::logAction(
                $user,
                'ADD_STUDENTS_BULK',
                count($toInsert) . " student(s) added to class_id={$classId}"
            );
        }

        if (!empty($errors)) {
            $savedRegNos = array_column($toInsert, 0);
            $remainingRows = array_values(array_filter($rowsOut, fn($r) => !in_array($r['reg_no'], $savedRegNos, true)));

            $msg = implode("\n", $errors);
            if (!empty($toInsert)) {
                $msg .= "\n" . count($toInsert) . ' student(s) saved successfully. Please fix the row(s) above and save again for the rest.';
            }

            // The page is drawn straight away (not redirected), so the message is put in the
            // session for THIS page only - otherwise the teacher would never see what went wrong.
            session()->now('danger', $msg);

            return view('student_form_bulk', [
                'classes' => $classes,
                'rows' => $remainingRows,
                'selected_class' => $classId,
            ]);
        }

        return redirect()->route('students')
            ->with('success', count($toInsert) . ' student(s) registered successfully.');
    }

    public function showEdit($id)
    {
        $user = Auth::user();
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        if ($user->isClassTeacher() && (int)$student->class_id !== (int)$user->class_id) {
            return redirect()->route('students')->with('danger', 'You can only edit students in your own assigned class.');
        }

        $classes = $user->isClassTeacher()
            ? SchoolClass::where('id', $user->class_id)->get()
            : SchoolClass::orderBy('sort_order')->get();

        return view('student_form', [
            'classes' => $classes,
            'student' => $student,
            'mode' => 'edit',
        ]);
    }

    public function edit(Request $request, $id)
    {
        if ($request->isMethod('GET')) {
            return $this->showEdit($id);
        }

        $user = Auth::user();
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        if ($user->isClassTeacher() && (int)$student->class_id !== (int)$user->class_id) {
            return redirect()->route('students')->with('danger', 'You can only edit students in your own assigned class.');
        }

        $fullName = trim($request->input('full_name', ''));
        $classId = $request->input('class_id');
        $gender = trim($request->input('gender', ''));
        $genderConfirmed = $request->input('gender_confirmed') === 'on' ? 1 : 0;
        $regNo = trim((string) $request->input('reg_no', ''));

        if ($regNo === '') {
            return back()->withInput()->with('danger', 'Reg No is required.');
        }
        if ($fullName === '') {
            return back()->withInput()->with('danger', 'Full Name is required.');
        }
        if (!$classId || !SchoolClass::whereKey($classId)->exists()) {
            return back()->withInput()->with('danger', 'Please choose a valid Class.');
        }
        // A class teacher may edit students of their own class only, and may not move them out of it.
        if ($user->isClassTeacher() && (int) $classId !== (int) $user->class_id) {
            return back()->withInput()->with('danger', 'You can only keep students in your own assigned class. Ask the Headmaster to move a student to another class.');
        }
        if ($regError = $this->regNoError($regNo)) {
            return back()->withInput()->with('danger', $regError);
        }
        if (Student::where('reg_no', $regNo)->where('id', '!=', $id)->exists()) {
            return back()->withInput()->with('danger', 'This Reg No is already used by another student.');
        }

        $student->update([
            'reg_no' => $regNo,
            'full_name' => $fullName,
            'gender' => $gender ?: ($student->gender ?? 'Unknown'),
            'gender_confirmed' => $genderConfirmed,
            'class_id' => $classId,
        ]);

        SchoolService::logAction($user, 'EDIT_STUDENT', "{$fullName} ({$regNo})");

        return redirect()->route('students')->with('success', 'Student details updated successfully.');
    }

    /** Reg No is the school's own number, typed by the teacher. Returns a message when it cannot be used. */
    private function regNoError(string $regNo): ?string
    {
        if (mb_strlen($regNo) > 50) {
            return 'Reg No is too long (at most 50 characters).';
        }
        if (preg_match('/[\x00-\x1F\x7F<>]/u', $regNo)) {
            return 'Reg No contains characters that are not allowed.';
        }

        return null;
    }

    public function delete($id)
    {
        $user = Auth::user();
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        if ($user->isClassTeacher() && (int)$student->class_id !== (int)$user->class_id) {
            return redirect()->route('students')->with('danger', 'You can only remove students in your own assigned class.');
        }

        $student->update(['active' => 0]);
        SchoolService::logAction($user, 'DELETE_STUDENT', "{$student->full_name} ({$student->reg_no})");

        return redirect()->route('students')->with('info', "Student {$student->full_name} has been removed.");
    }

    public function restore($id)
    {
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        $student->update(['active' => 1]);
        SchoolService::logAction(Auth::user(), 'RESTORE_STUDENT', "{$student->full_name} ({$student->reg_no})");

        return redirect()->route('students', ['status' => 'removed'])
            ->with('success', "{$student->full_name} has been restored to the active list.");
    }

    public function purge($id)
    {
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        if ($student->active) {
            return redirect()->route('students')
                ->with('danger', 'This student is still active - remove them first, then you can permanently delete them.');
        }

        Mark::where('student_id', $id)->delete();
        $student->delete();

        SchoolService::logAction(Auth::user(), 'PURGE_STUDENT', "{$student->full_name} ({$student->reg_no})");

        return redirect()->route('students', ['status' => 'removed'])
            ->with('info', "{$student->full_name} and all their marks have been permanently deleted.");
    }

    public function history($id)
    {
        $user = Auth::user();
        $student = Student::find($id);
        if (!$student) {
            abort(404);
        }

        if ($user->isClassTeacher() && (int)$student->class_id !== (int)$user->class_id) {
            return redirect()->route('students')->with('danger', 'You can only view history for students in your own assigned class.');
        }

        $cls = SchoolClass::find($student->class_id);

        $examIds = Mark::where('student_id', $id)
            ->whereNotNull('score')
            ->select('exam_id')
            ->distinct()
            ->pluck('exam_id')
            ->toArray();

        $exams = Examination::whereIn('id', $examIds)->get()->keyBy('id');
        $history = [];
        foreach ($examIds as $examId) {
            $exam = $exams[$examId] ?? null;
            if (!$exam) {
                continue;
            }
            // Each exam is ranked within the class the pupil was in AT THAT TIME, so a promoted
            // pupil keeps last year's results (they were recorded under last year's class).
            $examClassId = SchoolService::classIdForStudentExam((int) $id, (int) $examId, (int) $student->class_id);
            [$subjects, $results] = SchoolService::computeClassResults($examClassId, $examId, $id);

            $myResult = null;
            foreach ($results as $r) {
                if ($r['student']['id'] == $id) {
                    $myResult = $r;
                    break;
                }
            }

            if ($myResult && $myResult['subjects_entered'] > 0) {
                $history[] = [
                    'exam' => $exam->toArray(),
                    'class_name' => SchoolClass::whereKey($examClassId)->value('name'),
                    'result' => $myResult,
                    'total_students' => count($results),
                ];
            }
        }

        usort($history, function ($a, $b) {
            $cmpYear = strcmp($b['exam']['academic_year'], $a['exam']['academic_year']);
            if ($cmpYear !== 0) {
                return $cmpYear;
            }
            return $b['exam']['id'] <=> $a['exam']['id'];
        });

        return view('student_history', [
            'student' => $student,
            'cls' => $cls,
            'history' => $history,
        ]);
    }
}
