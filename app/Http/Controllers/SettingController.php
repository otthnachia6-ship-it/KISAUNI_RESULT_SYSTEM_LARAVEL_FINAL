<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SchoolService;
use App\Support\UploadedImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class SettingController extends Controller
{
    public function settings(Request $request)
    {
        $user = Auth::user();

        if ($request->isMethod('post')) {
            $schoolName = trim((string) $request->input('school_name', ''));
            $academicYear = trim((string) $request->input('academic_year', ''));

            // Validate everything first, so a bad value never leaves settings half-saved.
            // An empty academic year would make the exams, marks and reports pages look empty.
            if ($schoolName === '') {
                return back()->withInput()->with('danger', 'School name cannot be empty.');
            }
            if (!preg_match('/^\d{4}([\/-]\d{4})?$/', $academicYear)) {
                return back()->withInput()->with('danger', 'Academic year must be a year such as 2026 (or 2026/2027).');
            }

            $ext = null;
            if ($request->hasFile('logo')) {
                $ext = UploadedImage::extension($request->file('logo')); // decided from the real file content, not its name
                if ($ext === null) {
                    return back()->withInput()->with('danger', 'Only PNG or JPG files are allowed for the logo.');
                }
            }

            Setting::set('school_name', $schoolName);
            Setting::set('academic_year', $academicYear);

            if ($ext !== null) {
                $file = $request->file('logo');
                $uploadDir = storage_path('app/uploads');
                if (!File::exists($uploadDir)) {
                    File::makeDirectory($uploadDir, 0755, true, true);
                }

                $filename = 'logo_' . time() . '.' . $ext;
                $file->move($uploadDir, $filename);

                Setting::set('logo_path', "uploads/{$filename}");
            }

            SchoolService::logAction($user, 'UPDATE_SETTINGS', 'School settings updated');

            return redirect()->route('settings_page')->with('success', 'School settings saved successfully.');
        }

        $current = Setting::pluck('value', 'key')->toArray();

        return view('settings', [
            'current' => $current,
        ]);
    }
}
