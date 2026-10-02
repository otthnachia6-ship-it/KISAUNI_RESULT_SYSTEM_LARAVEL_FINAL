<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExaminationController;
use App\Http\Controllers\MarksController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResultController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Public media serving
Route::get('/media/{filename}', [MediaController::class, 'serveMedia'])->name('serve_media');

// Authentication routes (Guest)
Route::get('/', [AuthController::class, 'index'])->name('index');
Route::match(['get', 'post'], '/login', [AuthController::class, 'login'])->name('login');
Route::match(['get', 'post'], '/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot_password');

// Authenticated routes
Route::middleware(['auth', 'no_cache'])->group(function () {
    Route::get('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::match(['get', 'post'], '/change-password', [AuthController::class, 'changePassword'])->name('change_password');
    Route::match(['get', 'post'], '/profile', [AuthController::class, 'profile'])->name('profile');
    Route::get('/dashboard', [DashboardController::class, 'dashboard'])->name('dashboard');

    // Students
    Route::get('/students', [StudentController::class, 'index'])->name('students');
    Route::match(['get', 'post'], '/students/add', [StudentController::class, 'add'])->name('add_student');
    Route::match(['get', 'post'], '/students/add-bulk', [StudentController::class, 'addBulk'])->name('add_students_bulk');
    Route::match(['get', 'post'], '/students/edit/{student_id}', [StudentController::class, 'edit'])->name('edit_student');
    Route::post('/students/delete/{student_id}', [StudentController::class, 'delete'])->name('delete_student');
    Route::get('/students/{student_id}/history', [StudentController::class, 'history'])->name('student_history');

    // Promotion
    Route::match(['get', 'post'], '/promote', [ClassController::class, 'promote'])->name('promote_students');

    // Subjects & Examinations
    Route::match(['get', 'post'], '/subjects', [SubjectController::class, 'index'])->name('subjects');
    Route::match(['get', 'post'], '/examinations', [ExaminationController::class, 'index'])->name('examinations');

    // Marks Entry
    Route::get('/marks', [MarksController::class, 'marksSelect'])->name('marks_select');
    Route::match(['get', 'post'], '/marks/{exam_id}/{class_id}', [MarksController::class, 'marks'])->name('marks');
    Route::post('/marks/submit/{exam_id}/{class_id}', [MarksController::class, 'submit'])->name('submit_marks');

    // Performance Analytics, Reports & Records
    Route::get('/analytics', [AnalyticsController::class, 'analytics'])->name('performance_analytics');
    Route::get('/records', [RecordController::class, 'records'])->name('records');
    Route::get('/reports', [ReportController::class, 'reports'])->name('reports');
    Route::get('/reports/student/{student_id}/{exam_id}', [ReportController::class, 'studentReport'])->name('student_report');
    Route::get('/reports/student/{student_id}/{exam_id}/download', [ReportController::class, 'studentReportDownload'])->name('student_report_download');

    // APIs
    Route::get('/api/detect-gender', [ApiController::class, 'detectGender'])->name('api_detect_gender');
    Route::get('/api/keep-alive', [ApiController::class, 'keepAlive'])->name('api_keep_alive');
    Route::get('/api/students-by-class/{class_id}', [ApiController::class, 'studentsByClass'])->name('api_students_by_class');
    Route::get('/api/students-with-marks/{class_id}/{exam_id}', [ApiController::class, 'studentsWithMarks'])->name('api_students_with_marks');

    // Headmaster Only Routes
    Route::middleware(['headmaster'])->group(function () {
        // Classes management
        Route::match(['get', 'post'], '/classes', [ClassController::class, 'index'])->name('classes');
        Route::post('/classes/add', [ClassController::class, 'add'])->name('add_class');
        Route::post('/classes/delete/{class_id}', [ClassController::class, 'delete'])->name('delete_class');

        // Subjects & Examinations deletion
        Route::post('/subjects/delete/{subject_id}', [SubjectController::class, 'delete'])->name('delete_subject');
        Route::post('/examinations/delete/{exam_id}', [ExaminationController::class, 'delete'])->name('delete_examination');

        // Students restore & purge
        Route::post('/students/restore/{student_id}', [StudentController::class, 'restore'])->name('restore_student');
        Route::post('/students/purge/{student_id}', [StudentController::class, 'purge'])->name('purge_student');

        // Users / Accounts
        Route::get('/users', [UserController::class, 'index'])->name('users');
        Route::match(['get', 'post'], '/users/add', [UserController::class, 'add'])->name('add_user');
        Route::match(['get', 'post'], '/users/edit/{user_id}', [UserController::class, 'edit'])->name('edit_user');
        Route::post('/users/toggle/{user_id}', [UserController::class, 'toggle'])->name('toggle_user');
        Route::post('/users/delete/{user_id}', [UserController::class, 'delete'])->name('delete_user');

        // Results Review & Approval
        Route::get('/results', [ResultController::class, 'results'])->name('results');
        Route::match(['get', 'post'], '/results/review/{exam_id}/{class_id}', [ResultController::class, 'review'])->name('review_results');
        Route::get('/results/review/{exam_id}/{class_id}/download', [ResultController::class, 'reviewDownload'])->name('review_results_download');
        Route::get('/headmaster/overview', [ResultController::class, 'headmasterOverview'])->name('headmaster_overview');

        // Settings & Backups
        Route::match(['get', 'post'], '/settings', [SettingController::class, 'settings'])->name('settings_page');
        Route::post('/settings/backup/create', [SettingController::class, 'createBackup'])->name('create_backup_route');
        Route::get('/settings/backup/download/{filename}', [SettingController::class, 'downloadBackup'])->name('download_backup');
        Route::post('/settings/backup/restore/{filename}', [SettingController::class, 'restoreBackup'])->name('restore_backup_route');
        Route::post('/settings/backup/upload-restore', [SettingController::class, 'uploadRestoreBackup'])->name('upload_restore_backup_route');
        Route::post('/settings/backup/delete/{filename}', [SettingController::class, 'deleteBackup'])->name('delete_backup_route');

        // Audit Logs
        Route::get('/audit-logs', [AuditLogController::class, 'auditLogs'])->name('audit_logs');
    });
});
