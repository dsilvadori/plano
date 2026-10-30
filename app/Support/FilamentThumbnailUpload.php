<?php

namespace App\Support;

use Illuminate\Support\Arr;

class FilamentThumbnailUpload
{
    public static function store(mixed $state, string $directory): ?string
    {
        foreach (Arr::wrap($state) as $value) {
            $path = self::storeOne($value, $directory);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    protected static function storeOne(mixed $value, string $directory): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (! is_object($value)) {
            return null;
        }

        if (method_exists($value, 'storePublicly')) {
            $path = $value->storePublicly($directory, ['disk' => 'public']);

            return is_string($path) && $path !== '' ? $path : null;
        }

        if (method_exists($value, 'store')) {
            $path = $value->store($directory, ['disk' => 'public']);

            return is_string($path) && $path !== '' ? $path : null;
        }

        return null;
    }
}
