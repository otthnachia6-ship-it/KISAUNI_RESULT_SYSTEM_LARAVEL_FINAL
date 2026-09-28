<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;

class MediaController extends Controller
{
    public function serveMedia($filename)
    {
        $safeName = basename($filename);
        $path = storage_path('app/uploads/' . $safeName);

        if (!File::exists($path)) {
            abort(404);
        }

        $mime = File::mimeType($path) ?: 'application/octet-stream';
        return response()->file($path, ['Content-Type' => $mime]);
    }
}
