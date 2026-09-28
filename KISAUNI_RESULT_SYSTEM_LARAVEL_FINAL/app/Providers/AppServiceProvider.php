<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\SchoolService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (class_exists(\Laravel\Passport\Passport::class)) {
            \Laravel\Passport\Passport::ignoreMigrations();
        }

        View::composer('*', function ($view) {
            $schoolName = Setting::get('school_name', SchoolService::DEFAULT_SCHOOL_NAME);
            $academicYear = Setting::get('academic_year', SchoolService::DEFAULT_ACADEMIC_YEAR);
            $logoPath = Setting::get('logo_path', 'images/logo.png');
            $sessionUser = Auth::user();

            $logoUrl = function ($path) {
                if (!$path) {
                    return asset('images/logo.png');
                }
                if (str_starts_with($path, 'uploads/')) {
                    return route('serve_media', ['filename' => substr($path, 8)]);
                }
                return asset($path);
            };

            $userPhotoUrl = function ($path) {
                if (!$path) {
                    return null;
                }
                if (str_starts_with($path, 'uploads/')) {
                    return route('serve_media', ['filename' => substr($path, 8)]);
                }
                return asset($path);
            };

            $assetVersion = function ($relPath) {
                $abs = public_path($relPath);
                if (File::exists($abs)) {
                    return filemtime($abs);
                }
                return 1;
            };

            $view->with([
                'school_name' => $schoolName,
                'academic_year' => $academicYear,
                'logo_path' => $logoPath,
                'session_user' => $sessionUser,
                'ROLE_HEADMASTER' => SchoolService::ROLE_HEADMASTER,
                'ROLE_CLASS_TEACHER' => SchoolService::ROLE_CLASS_TEACHER,
                'logo_url' => $logoUrl,
                'user_photo_url' => $userPhotoUrl,
                'asset_version' => $assetVersion,
                'IDLE_TIMEOUT_MINUTES' => config('kisauni.idle_timeout_minutes'),
            ]);
        });
    }
}
