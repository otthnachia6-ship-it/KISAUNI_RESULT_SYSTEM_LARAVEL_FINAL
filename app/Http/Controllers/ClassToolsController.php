<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\ClassDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

/** Headmaster-only "Class tools", opened from the Classes menu. */
class ClassToolsController extends Controller
{
    private function classOrFail($id): SchoolClass
    {
        abort_unless(Auth::user()?->hasHeadmasterAccess(), 403);
        return SchoolClass::findOrFail($id);
    }

    public function show($class_id)
    {
        $class = $this->classOrFail($class_id);

        return view('class_tools', [
            'class' => $class,
            'data' => ClassDataService::overview($class),
            'actions' => ClassDataService::actions(),
        ]);
    }

    public function preview(Request $request, $class_id)
    {
        $class = $this->classOrFail($class_id);

        if ($request->input('action') === ClassDataService::EDIT_MARKS) {
            $examId = (int) $request->input('edit_exam');
            if (!ClassDataService::hasExam($class, $examId)) {
                return redirect()->route('class_tools', $class->id)->with('danger', 'Choose an examination.')->withInput();
            }
            return redirect()->route('class_tools_marks', [$class->id, $examId]);
        }

        $plan = ClassDataService::plan($class, $request->all());

        if (!$plan['ok']) {
            return redirect()->route('class_tools', $class->id)->with('danger', $plan['error'])->withInput();
        }

        return view('class_tools_confirm', ['class' => $class, 'plan' => $plan]);
    }

    public function run(Request $request, $class_id)
    {
        $class = $this->classOrFail($class_id);
        $plan = ClassDataService::plan($class, $request->all());

        try {
            ClassDataService::execute(Auth::user(), $class, $plan);
        } catch (RuntimeException $e) {
            return redirect()->route('class_tools', $class->id)->with('danger', $e->getMessage());
        }

        if (in_array($plan['action'], [ClassDataService::CLASS_KEEP, ClassDataService::CLASS_ALL], true)) {
            return redirect()->route('classes')->with('info', "Class '{$class->name}' deleted.");
        }

        return redirect()->route('class_tools', $class->id)->with('info', 'Done.');
    }

    public function marks($class_id, $exam_id)
    {
        $class = $this->classOrFail($class_id);
        abort_unless(ClassDataService::hasExam($class, (int) $exam_id), 404);

        return view('class_tools_marks', [
            'class' => $class,
            'exam' => \App\Models\Examination::findOrFail($exam_id),
            'grid' => ClassDataService::marksGrid($class, (int) $exam_id),
        ]);
    }

    public function saveMarks(Request $request, $class_id, $exam_id)
    {
        $class = $this->classOrFail($class_id);
        abort_unless(ClassDataService::hasExam($class, (int) $exam_id), 404);

        try {
            $changed = ClassDataService::saveMarks(Auth::user(), $class, (int) $exam_id, (array) $request->input('marks', []));
        } catch (InvalidArgumentException $e) {
            return redirect()->route('class_tools_marks', [$class->id, $exam_id])->with('danger', $e->getMessage())->withInput();
        } catch (RuntimeException $e) {
            return redirect()->route('class_tools_marks', [$class->id, $exam_id])->with('danger', $e->getMessage())->withInput();
        }

        return redirect()->route('class_tools_marks', [$class->id, $exam_id])
            ->with($changed ? 'success' : 'info', $changed ? "Saved and approved ({$changed} changed)." : 'No changes.');
    }
}
