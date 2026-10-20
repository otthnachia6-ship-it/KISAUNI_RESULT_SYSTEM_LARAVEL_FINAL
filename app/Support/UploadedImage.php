<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

final class UploadedImage
{
    /**
     * 'png' or 'jpg' when the file CONTENT really is that kind of image, otherwise null.
     * The name the browser sent is never trusted: a web page renamed to photo.jpg is rejected.
     */
    public static function extension(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();
        if (!$path || !is_file($path)) {
            return null;
        }

        $info = @getimagesize($path);
        if ($info === false) {
            return null;
        }

        return match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            default => null,
        };
    }
}
