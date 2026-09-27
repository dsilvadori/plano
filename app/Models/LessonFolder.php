<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LessonFolder extends Model
{
    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'path',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    public static function findOrCreatePath(?string $path, array $metadata = []): ?self
    {
        $segments = collect(preg_split('/\s*\/\s*/', (string) $path) ?: [])
            ->map(fn (string $segment): string => trim($segment))
            ->filter()
            ->values();

        if ($segments->isEmpty()) {
            return null;
        }

        $parent = null;
        $currentPath = '';

        foreach ($segments as $index => $segment) {
            $slug = Str::slug($segment) ?: 'pasta-'.($index + 1);
            $currentPath = $currentPath === '' ? $slug : $currentPath.'/'.$slug;
            $folder = self::query()->firstOrCreate(
                ['path' => $currentPath],
                [
                    'parent_id' => $parent?->id,
                    'name' => $segment,
                    'slug' => $slug,
                    'sort_order' => $index + 1,
                    'metadata' => $metadata,
                ],
            );

            if ($folder->parent_id !== ($parent?->id)) {
                $folder->forceFill(['parent_id' => $parent?->id])->save();
            }

            $parent = $folder;
        }

        return $parent;
    }
}
