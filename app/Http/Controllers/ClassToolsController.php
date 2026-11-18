<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\ClassDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** Headmaster-only "Class tools", opened from the Classes menu. */
class ClassToolsController extends Controller
{
    private function classOrFail($id): SchoolClass
    {
        abort_unless(Auth::user()?->isHeadmaster(), 403);
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
}
