<?php

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class CourseSpreadsheetUpload
{
    public static function absolutePath(mixed $state, string $disk = 'local'): ?string
    {
        $storedPath = self::storedPathFromState($state);

        if ($storedPath !== null) {
            return Storage::disk($disk)->path($storedPath);
        }

        $temporaryFile = self::temporaryFileFromState($state);

        return $temporaryFile?->getRealPath();
    }

    public static function storedPath(mixed $state, string $directory = 'imports/courses', string $disk = 'local'): ?string
    {
        $storedPath = self::storedPathFromState($state);

        if ($storedPath !== null) {
            return $storedPath;
        }

        $temporaryFile = self::temporaryFileFromState($state);

        if (! $temporaryFile instanceof TemporaryUploadedFile || ! $temporaryFile->exists()) {
            return null;
        }

        return $temporaryFile->storeAs(
            $directory,
            self::storageFilename($temporaryFile),
            ['disk' => $disk],
        );
    }

    protected static function storedPathFromState(mixed $state): ?string
    {
        if (is_string($state) && $state !== '') {
            return $state;
        }

        if (! is_array($state)) {
            return null;
        }

        $value = Arr::first(Arr::flatten($state), fn (mixed $value): bool => is_string($value) && $value !== '');

        return is_string($value) ? $value : null;
    }

    protected static function temporaryFileFromState(mixed $state): ?TemporaryUploadedFile
    {
        if ($state instanceof TemporaryUploadedFile) {
            return $state;
        }

        if (! is_array($state)) {
            return null;
        }

        foreach (Arr::flatten($state) as $value) {
            if ($value instanceof TemporaryUploadedFile) {
                return $value;
            }
        }

        return null;
    }

    protected static function storageFilename(TemporaryUploadedFile $file): string
    {
        $originalName = trim($file->getClientOriginalName()) ?: $file->getFilename();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $basename = pathinfo($originalName, PATHINFO_FILENAME);
        $safeBasename = Str::slug($basename) ?: 'planilha';
        $suffix = now()->format('YmdHis').'-'.Str::random(8);

        return $safeBasename.'-'.$suffix.($extension ? '.'.$extension : '');
    }
}
