<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\OzemsImporter;
use App\Services\ZemsStudentImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Import pupils from a ZEMS result file into one class (Students > Import from ZEMS).
 * Class Teachers import into their own class only; the Headmaster picks the class.
 * Nothing is saved until the user has looked at the list and confirmed.
 */
class StudentImportController extends Controller
{
    private function allowedClasses($user)
    {
        return $user->isClassTeacher()
            ? SchoolClass::where('id', $user->class_id)->get()
            : SchoolClass::orderBy('sort_order')->get();
    }

    public function form()
    {
        $user = Auth::user();
        if ($user->isClassTeacher() && (!$user->class_id || !SchoolClass::find($user->class_id))) {
            return redirect()->route('students')->with('danger', 'You are not assigned to a class yet. Contact the Headmaster.');
        }

        return view('students_import', ['classes' => $this->allowedClasses($user)]);
    }

    public function upload(Request $request)
    {
        $user = Auth::user();

        $classId = $user->isClassTeacher() ? (int) $user->class_id : (int) $request->input('class_id');
        $class = $classId ? SchoolClass::find($classId) : null;
        if (!$class) {
            return redirect()->route('students_import')->with('danger', 'Please choose the class.');
        }

        $validator = Validator::make($request->all(), ['file' => 'required|file|max:4096'], [
            'file.required' => 'Please choose the Excel file exported from ZEMS.',
            'file.max' => 'The file is too large (maximum 4 MB).',
            'file.file' => 'The file could not be uploaded. Please try again.',
        ]);
        if ($validator->fails()) {
            return redirect()->route('students_import')->with('danger', $validator->errors()->first());
        }

        $file = $request->file('file');
        if (!in_array(strtolower($file->getClientOriginalExtension()), ['xls', 'csv'], true)) {
            return redirect()->route('students_import')
                ->with('danger', 'Please upload the Excel file (.xls) exactly as exported from ZEMS. A .csv file also works.');
        }

        try {
            $parsed = OzemsImporter::parse($file->getRealPath());
        } catch (InvalidArgumentException $e) {
            return redirect()->route('students_import')->with('danger', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('students_import')->with('danger', 'The file could not be read. Please upload it exactly as exported from ZEMS.');
        }

        if ($parsed['std'] !== null && $class->standard !== null && (int) $parsed['std'] !== (int) $class->standard) {
            return redirect()->route('students_import')->with('danger',
                "This file is for Standard {$parsed['std']}, but {$class->name} is a different standard. Please choose the right class or file.");
        }

        session(['zems_import' => [
            'token' => Str::random(24),
            'class_id' => $class->id,
            'rows' => $parsed['rows'],
            'std' => $parsed['std'],
            'year' => $parsed['year'],
        ]]);

        return redirect()->route('students_import_preview');
    }

    public function preview()
    {
        $user = Auth::user();
        $data = session('zems_import');
        $class = $data ? SchoolClass::find($data['class_id']) : null;
        if (!$data || !$class || ($user->isClassTeacher() && (int) $user->class_id !== (int) $class->id)) {
            return redirect()->route('students_import')->with('danger', 'Please choose the file again.');
        }

        $analysis = ZemsStudentImporter::analyse($data['rows'], (int) $class->id);

        return view('students_import_preview', [
            'cls' => $class,
            'analysis' => $analysis,
            'token' => $data['token'],
            'file_std' => $data['std'],
            'file_year' => $data['year'],
        ]);
    }

    public function confirm(Request $request)
    {
        $user = Auth::user();
        $data = session('zems_import');
        $class = $data ? SchoolClass::find($data['class_id']) : null;
        if (!$data || !$class || ($user->isClassTeacher() && (int) $user->class_id !== (int) $class->id)
            || !hash_equals($data['token'], (string) $request->input('token'))) {
            return redirect()->route('students_import')->with('danger', 'This page has expired. Please choose the file again.');
        }

        $selected = [];
        foreach ((array) $request->input('selected', []) as $z) {
            $z = OzemsImporter::normalizeZems((string) $z);
            if ($z !== null) {
                $selected[] = $z;
            }
        }

        try {
            $result = ZemsStudentImporter::commit($user, $class, $data['rows'], $selected);
        } catch (\Throwable $e) {
            report($e);
            return redirect()->route('students_import_preview')
                ->with('danger', 'Saving failed and nothing was registered. Please try again.');
        }

        session()->forget('zems_import');

        $parts = [];
        if ($result['registered'] > 0) {
            $parts[] = "{$result['registered']} pupil(s) registered in {$class->name}";
        }
        if ($result['linked'] > 0) {
            $parts[] = "{$result['linked']} existing pupil(s) linked to their ZEMS number";
        }
        if (empty($parts)) {
            return redirect()->route('students_import')->with('warning', 'Nothing was registered. Tick the pupils of your class first.');
        }

        $text = implode(' and ', $parts) . '.';
        $redirect = redirect()->route('students', $user->isClassTeacher() ? [] : ['class_id' => $class->id]);
        if (!empty($result['conflicts'])) {
            return $redirect->with('warning', $text . ' Skipped (number already in use): ' . implode(', ', array_slice($result['conflicts'], 0, 10)) . '.');
        }

        return $redirect->with('success', $text);
    }
}
