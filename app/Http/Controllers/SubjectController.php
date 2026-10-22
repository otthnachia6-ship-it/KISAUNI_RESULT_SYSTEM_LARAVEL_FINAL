<?php

namespace App\Http\Controllers;

use App\Models\Mark;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\OzemsImporter;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        if ($request->isMethod('post') && $user->isHeadmaster()) {
            $action = $request->input('action');

            if ($action === 'add_subject') {
                $name = trim($request->input('name', ''));
                $code = OzemsImporter::normalizeCode($request->input('subject_code', ''));
                $classIds = $request->input('class_ids', []);
                if ($name !== '') {
                    $error = $this->subjectError($name, $code, null);
                    if ($error) {
                        return redirect()->route('subjects')->with('danger', $error);
                    }

                    $sub = Subject::create(['name' => $name, 'subject_code' => $code !== '' ? $code : null]);
                    foreach ($classIds as $cid) {
                        DB::table('class_subjects')->insertOrIgnore([
                            'class_id' => $cid,
                            'subject_id' => $sub->id,
                        ]);
                    }
                    SchoolService::logAction($user, 'ADD_SUBJECT', $code !== '' ? "{$name} (code {$code})" : $name);
                    return redirect()->route('subjects')->with('success', "Subject '{$name}' added successfully.");
                }
            } elseif ($action === 'update_subject') {
                $subject = Subject::find($request->input('subject_id'));
                if (!$subject) {
                    return redirect()->route('subjects')->with('danger', 'That subject no longer exists.');
                }

                $name = trim($request->input('name', ''));
                $code = OzemsImporter::normalizeCode($request->input('subject_code', ''));
                if ($name === '') {
                    return redirect()->route('subjects')->with('danger', 'The subject name cannot be empty.');
                }
                $error = $this->subjectError($name, $code, $subject->id);
                if ($error) {
                    return redirect()->route('subjects')->with('danger', $error);
                }

                $before = $subject->subject_code ? "{$subject->name} (code {$subject->subject_code})" : $subject->name;
                $subject->update(['name' => $name, 'subject_code' => $code !== '' ? $code : null]);
                $after = $code !== '' ? "{$name} (code {$code})" : $name;
                SchoolService::logAction($user, 'UPDATE_SUBJECT', "{$before} -> {$after}");

                return redirect()->route('subjects')->with('success', "Subject '{$name}' updated successfully.");
            } elseif ($action === 'toggle') {
                $classId = $request->input('class_id');
                $subjectId = $request->input('subject_id');

                $exists = DB::table('class_subjects')
                    ->where('class_id', $classId)
                    ->where('subject_id', $subjectId)
                    ->exists();

                if ($exists) {
                    $hasMarks = DB::table('marks as m')
                        ->join('students as s', 'm.student_id', '=', 's.id')
                        ->where('s.class_id', $classId)
                        ->where('m.subject_id', $subjectId)
                        ->whereNotNull('m.score')
                        ->exists();

                    if ($hasMarks) {
                        $subj = Subject::find($subjectId);
                        $cls = SchoolClass::find($classId);
                        return redirect()->route('subjects')->with(
                            'danger',
                            "Cannot remove '{$subj->name}' from {$cls->name} - marks have already been recorded for it in that class. It will stay assigned to protect that data."
                        );
                    } else {
                        DB::table('class_subjects')
                            ->where('class_id', $classId)
                            ->where('subject_id', $subjectId)
                            ->delete();
                    }
                } else {
                    $lockedExam = DB::table('exam_class_status as ecs')
                        ->join('examinations as e', 'ecs.exam_id', '=', 'e.id')
                        ->where('ecs.class_id', $classId)
                        ->whereIn('ecs.status', ['submitted', 'under_review', 'approved'])
                        ->select('ecs.*', 'e.exam_type', 'e.academic_year')
                        ->first();

                    if ($lockedExam) {
                        $subj = Subject::find($subjectId);
                        $cls = SchoolClass::find($classId);
                        return redirect()->route('subjects')->with(
                            'danger',
                            "Cannot add '{$subj->name}' to {$cls->name} - examination '{$lockedExam->exam_type} ({$lockedExam->academic_year})' is currently locked/submitted for this class. Unlock it first if you need to change its subjects."
                        );
                    } else {
                        DB::table('class_subjects')->insertOrIgnore([
                            'class_id' => $classId,
                            'subject_id' => $subjectId,
                        ]);
                    }
                }

                return redirect()->route('subjects');
            }
        }

        $classes = SchoolClass::orderBy('sort_order')->get();
        $allSubjects = Subject::orderBy('name')->get();

        $mappingRows = DB::table('class_subjects')->get();
        $mappingSet = [];
        foreach ($mappingRows as $m) {
            $mappingSet[$m->class_id][$m->subject_id] = true;
        }

        return view('subjects', [
            'classes' => $classes,
            'all_subjects' => $allSubjects,
            'mapping_set' => $mappingSet,
        ]);
    }

    /**
     * Returns a message when the name/code cannot be used, or null when it is fine.
     * $ignoreId is the subject being edited (it may keep its own name/code).
     */
    private function subjectError(string $name, string $code, ?int $ignoreId): ?string
    {
        if (mb_strlen($name) > 191) {
            return 'The subject name is too long (maximum 191 characters).';
        }

        $sameName = Subject::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
        if ($sameName) {
            return "A subject named '{$name}' already exists.";
        }

        if ($code !== '') {
            if (!preg_match('/^[A-Z0-9]{1,20}$/', $code)) {
                return 'The subject code may only contain letters and numbers (maximum 20), for example 104.';
            }
            $owner = Subject::where('subject_code', $code)
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->first();
            if ($owner) {
                return "The code {$code} is already used by '{$owner->name}'.";
            }
        }

        return null;
    }

    public function delete($id)
    {
        $user = Auth::user();
        if (!$user->isHeadmaster()) {
            abort(403);
        }

        $subject = Subject::find($id);
        if (!$subject) {
            abort(404);
        }

        $hasMarks = Mark::where('subject_id', $id)->exists();
        if ($hasMarks) {
            return redirect()->route('subjects')->with(
                'danger',
                "Cannot delete '{$subject->name}' — marks have already been recorded for it. Remove it from all classes instead (toggle off) if it is no longer taught."
            );
        }

        DB::table('class_subjects')->where('subject_id', $id)->delete();
        $subject->delete();

        SchoolService::logAction($user, 'DELETE_SUBJECT', $subject->name);

        return redirect()->route('subjects')->with('info', "Subject '{$subject->name}' deleted successfully.");
    }
}
