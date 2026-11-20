<?php

namespace App\Http\Controllers\Developer;

use App\Http\Controllers\Controller;
use App\Services\DatabaseBackupService;
use App\Services\SchoolService;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Developer-only database backups. Reuses DatabaseBackupService (the same verified
 * mysqldump/PHP dump used by `php artisan db:backup`).
 *
 *  - Create: POST + CSRF, then the service writes, verifies and only then keeps the file.
 *  - Download: the file name must be one the service created and lists; the file is
 *    verified again before it is sent; served through this controller only (no public URL).
 *  - No restore and no delete from the web. Nothing here writes to school data.
 */
class BackupController extends Controller
{
    private function files(): array
    {
        try {
            return DatabaseBackupService::listBackupFiles();
        } catch (Throwable) {
            return [];
        }
    }

    private function directoryError(): ?string
    {
        try {
            DatabaseBackupService::directory();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** "kisauni_db_20261120_101500_developer.sql.gz" -> "developer" */
    public static function labelOf(string $name): string
    {
        return preg_match('/^kisauni_db_\d{8}_\d{6}_([A-Za-z0-9\-]+)\.sql\.gz$/', $name, $m) ? $m[1] : 'automatic (cron)';
    }

    public function database()
    {
        return view('developer.backups.database', [
            'checks' => SystemHealthService::database(),
            'tables' => SystemHealthService::tableCounts(),
        ]);
    }

    public function create()
    {
        return view('developer.backups.create', [
            'status' => DatabaseBackupService::status(),
            'dir_error' => $this->directoryError(),
            'latest' => $this->files()[0] ?? null,
        ]);
    }

    public function store()
    {
        $user = Auth::user();
        try {
            $result = DatabaseBackupService::run('developer', false);
        } catch (Throwable $e) {
            SchoolService::logAction($user, 'CREATE_BACKUP_FAILED', 'Backup attempt failed: ' . mb_substr($e->getMessage(), 0, 200));

            return redirect()->route('developer.backups.create')
                ->with('danger', 'The backup was NOT created: ' . $e->getMessage());
        }

        SchoolService::logAction($user, 'CREATE_BACKUP', "{$result['file']} (" . SystemHealthService::bytes($result['size']) . ", {$result['method']}, verified)");

        return redirect()->route('developer.backups.downloads')
            ->with('success', "Backup created and verified: {$result['file']} (" . SystemHealthService::bytes($result['size']) . '). You can download it below.');
    }

    public function downloads()
    {
        return view('developer.backups.downloads', [
            'files' => $this->files(),
            'dir_error' => $this->directoryError(),
        ]);
    }

    public function history()
    {
        $events = DB::table('audit_log')
            ->whereIn('action', ['CREATE_BACKUP', 'CREATE_BACKUP_FAILED', 'DOWNLOAD_BACKUP'])
            ->orderBy('id', 'desc')->limit(100)->get();

        return view('developer.backups.history', [
            'files' => $this->files(),
            'events' => $events,
            'retention_days' => SchoolService::auditLogRetentionDays(),
        ]);
    }

    public function status()
    {
        return view('developer.backups.status', [
            'status' => DatabaseBackupService::status(),
            'health' => SystemHealthService::backupHealth(),
            'count' => count($this->files()),
            'total_size' => array_sum(array_column($this->files(), 'size')),
            'dir_error' => $this->directoryError(),
        ]);
    }

    public function download(string $file)
    {
        $user = Auth::user();
        $path = DatabaseBackupService::findBackup($file);
        if (!$path) {
            abort(404);
        }

        try {
            DatabaseBackupService::verifyDump($path);
        } catch (Throwable $e) {
            SchoolService::logAction($user, 'DOWNLOAD_BACKUP_REFUSED', "{$file}: failed verification");

            return redirect()->route('developer.backups.downloads')
                ->with('danger', "{$file} failed verification and was not downloaded: " . $e->getMessage());
        }

        SchoolService::logAction($user, 'DOWNLOAD_BACKUP', "{$file} (" . SystemHealthService::bytes((int) filesize($path)) . ')');

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/gzip',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
