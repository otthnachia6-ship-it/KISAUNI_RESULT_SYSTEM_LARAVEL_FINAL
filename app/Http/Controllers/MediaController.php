<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    /** Only these picture types are ever served from the uploads folder. */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function serveMedia($filename)
    {
        $safeName = basename($filename);
        $type = self::TYPES[strtolower(pathinfo($safeName, PATHINFO_EXTENSION))] ?? null;
        if ($type === null) {
            abort(404);
        }

        $path = storage_path('app/uploads/' . $safeName);
        if (!File::exists($path)) {
            abort(404);
        }

        // The type comes from the whitelist above, never from the file content,
        // and nosniff stops a browser from guessing a different type.
        return response()->file($path, [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
