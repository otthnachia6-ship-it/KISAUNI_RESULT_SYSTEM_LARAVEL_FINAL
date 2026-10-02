<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SchoolService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;

class SettingController extends Controller
{
    public function settings(Request $request)
    {
        $user = Auth::user();

        if ($request->isMethod('post')) {
            $schoolName = trim($request->input('school_name', ''));
            $academicYear = trim($request->input('academic_year', ''));

            Setting::set('school_name', $schoolName);
            Setting::set('academic_year', $academicYear);

            if ($request->hasFile('logo')) {
                $file = $request->file('logo');
                $ext = strtolower($file->getClientOriginalExtension());
                if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                    return back()->with('danger', 'Only PNG or JPG files are allowed for the logo.');
                }

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
