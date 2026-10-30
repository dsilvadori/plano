<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ThumbnailController extends Controller
{
    public function __invoke(string $path): BinaryFileResponse|Response
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($path, '..') || ! $this->isAllowedPath($path) || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path), [
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    protected function isAllowedPath(string $path): bool
    {
        return str_starts_with($path, 'course-thumbnails/')
            || str_starts_with($path, 'track-thumbnails/')
            || str_starts_with($path, 'teacher-thumbnails/');
    }
}
