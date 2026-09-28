<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\BackupService;
use App\Services\SchoolService;
use Carbon\Carbon;
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

        $driver = config('database.default');
        $dbExists = false;
        $dbSizeKb = 0;
        $dbModified = null;

        if ($driver === 'sqlite') {
            $dbPath = config('database.connections.sqlite.database');
            $dbExists = File::exists($dbPath);
            if ($dbExists) {
                $dbSizeKb = round(filesize($dbPath) / 1024, 1);
                $dbModified = Carbon::createFromTimestamp(filemtime($dbPath), 'Africa/Dar_es_Salaam')->format('Y-m-d H:i:s');
            }
        } else {
            $dbExists = true;
            $dbPath = config('database.connections.mysql.database');
        }

        $backups = BackupService::listBackups();
        $offsiteDays = BackupService::daysSinceLastOffsiteDownload();

        return view('settings', [
            'current' => $current,
            'backups' => $backups,
            'db_path' => $dbPath ?? 'kisauni_results',
            'db_size_kb' => $dbSizeKb,
            'db_modified' => $dbModified,
            'last_backup' => $backups[0] ?? null,
            'offsite_days' => $offsiteDays,
            'backup_retention_days' => config('kisauni.backup_retention_days'),
            'backup_min_keep' => config('kisauni.backup_min_keep'),
        ]);
    }

    public function createBackup()
    {
        $filename = BackupService::createBackup('manual');
        if ($filename) {
            SchoolService::logAction(Auth::user(), 'CREATE_BACKUP', $filename);
            return redirect()->route('settings_page')->with('success', "Backup created successfully: {$filename}");
        }
        return redirect()->route('settings_page')->with('danger', 'Could not create a backup.');
    }

    public function downloadBackup($filename)
    {
        $safeName = basename($filename);
        $path = BackupService::getBackupDir() . DIRECTORY_SEPARATOR . $safeName;

        if (!str_starts_with($safeName, BackupService::BACKUP_FILENAME_PREFIX) || !File::exists($path)) {
            abort(404);
        }

        SchoolService::logAction(Auth::user(), 'DOWNLOAD_BACKUP', $safeName);

        return response()->download($path, $safeName);
    }

    public function restoreBackup(Request $request, $filename)
    {
        $ok = BackupService::restoreBackup($filename);
        if ($ok) {
            SchoolService::logAction(Auth::user(), 'RESTORE_BACKUP', $filename);
            return redirect()->route('settings_page')->with(
                'success',
                'Database restored from that backup successfully. A safety copy of the data from just before the restore was also saved, in case you need to undo this.'
            );
        }
        return redirect()->route('settings_page')->with('danger', 'Could not restore that backup file - it may have been removed.');
    }

    public function uploadRestoreBackup(Request $request)
    {
        if (!$request->hasFile('backup_file')) {
            return redirect()->route('settings_page')->with('danger', 'Please choose a backup (.sql or .db) file to upload first.');
        }

        $file = $request->file('backup_file');
        $origName = $file->getClientOriginalName();

        [$ok, $reason] = BackupService::restoreBackupFromUpload($file);
        if ($ok) {
            SchoolService::logAction(Auth::user(), 'RESTORE_BACKUP_UPLOAD', $origName);
            return redirect()->route('settings_page')->with(
                'success',
                'Database restored successfully from the uploaded backup. A safety copy of the data from just before the restore was also saved, in case you need to undo this.'
            );
        }

        return redirect()->route('settings_page')->with('danger', "Could not restore from that file: {$reason}");
    }

    public function deleteBackup(Request $request, $filename)
    {
        $ok = BackupService::deleteBackup($filename);
        if ($ok) {
            SchoolService::logAction(Auth::user(), 'DELETE_BACKUP', basename($filename));
            return redirect()->route('settings_page')->with('info', 'Backup file deleted.');
        }
        return redirect()->route('settings_page')->with('warning', 'That backup file could not be found.');
    }
}
