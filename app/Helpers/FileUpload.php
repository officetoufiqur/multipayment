<?php

namespace App\Helpers;

use Illuminate\Http\UploadedFile;

class FileUpload
{
    public static function storeFile(UploadedFile $file, string $directory): string
    {
        $filename = time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
        $storedPath = $file->storeAs($directory, $filename, 'public');

        return '/storage/'.$storedPath;
    }

    public static function updateFile(UploadedFile $file, string $directory, ?string $oldFilePath = null): string
    {
        if ($oldFilePath && file_exists(public_path($oldFilePath))) {
            @unlink(public_path($oldFilePath));
        }

        return self::storeFile($file, $directory);
    }

    public static function deleteFile(?string $filePath): void
    {
        if ($filePath && file_exists(public_path($filePath))) {
            @unlink(public_path($filePath));
        }
    }
}
