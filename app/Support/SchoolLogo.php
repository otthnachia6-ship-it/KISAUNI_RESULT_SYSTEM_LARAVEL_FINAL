<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * The school's real logo as a self-contained data: URI.
 *
 * Used by the maintenance pages: they are rendered once when maintenance is switched on
 * and then served while the application is down, so they cannot load the uploaded logo
 * through /media/... (that route is part of the stopped application). Embedding the
 * image keeps the real logo visible. Falls back to public/images/logo.png.
 */
final class SchoolLogo
{
    private const MAX_BYTES = 600 * 1024;

    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    public static function dataUri(): ?string
    {
        foreach ([static::configuredPath(), public_path('images/logo.png')] as $file) {
            if (!$file || !is_file($file) || !is_readable($file)) {
                continue;
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $size = (int) filesize($file);
            if (!isset(static::TYPES[$ext]) || $size === 0 || $size > static::MAX_BYTES) {
                continue;
            }
            $bytes = @file_get_contents($file);
            if ($bytes !== false) {
                return 'data:' . static::TYPES[$ext] . ';base64,' . base64_encode($bytes);
            }
        }

        return null;
    }

    /** Absolute path of the logo chosen in Settings (uploaded or bundled), or null. */
    private static function configuredPath(): ?string
    {
        try {
            $path = (string) Setting::get('logo_path', 'images/logo.png');
        } catch (Throwable) {
            return null; // database unavailable: use the bundled logo
        }
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }
        if (str_starts_with($path, 'uploads/')) {
            return storage_path('app/uploads/' . basename(substr($path, 8)));
        }

        return public_path(ltrim($path, '/'));
    }
}
