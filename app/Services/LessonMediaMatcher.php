<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LessonMediaMatcher
{
    public function matchLessons(array $lessons, array $mediaFiles, float $minimumConfidence = 0.72): array
    {
        $indexedMedia = collect($mediaFiles)
            ->map(fn (array $file): array => $this->normalizeMediaFile($file))
            ->filter(fn (array $file): bool => filled($file['name'] ?? null))
            ->values();

        return collect($lessons)
            ->map(function (array $lesson) use ($indexedMedia, $minimumConfidence): array {
                $match = $this->bestMatch((string) ($lesson['name'] ?? ''), $indexedMedia);

                if (! $match || $match['confidence'] < $minimumConfidence) {
                    return array_merge($this->clearMediaFields($lesson), [
                        'has_media' => false,
                        'media_status' => 'missing',
                        'is_published' => false,
                        'published_at' => null,
                        'media_match_confidence' => $match['confidence'] ?? null,
                        'media_candidate_name' => $match['name'] ?? null,
                    ]);
                }

                return array_merge($lesson, [
                    'has_media' => true,
                    'media_status' => 'imported',
                    'is_published' => true,
                    'published_at' => now()->toISOString(),
                    'media_source' => $match['source'] ?? 'google_drive',
                    'media_file_id' => $match['id'] ?? null,
                    'media_name' => $match['name'],
                    'media_mime_type' => $match['mime_type'] ?? null,
                    'media_url' => $match['web_url'] ?? null,
                    'media_match_confidence' => $match['confidence'],
                    'media_matched_at' => now()->toISOString(),
                ]);
            })
            ->values()
            ->all();
    }

    public function normalizeTitle(string $value): string
    {
        $withoutExtension = preg_replace('/\.(mp4|mov|m4v|avi|mkv|webm|mp3|m4a|wav)$/iu', '', $value) ?? $value;

        return Str::of($withoutExtension)
            ->ascii()
            ->lower()
            ->replaceMatches('/\b(aula|video|vídeo|parte|modulo|m[oó]dulo|trilha)\b/u', ' ')
            ->replaceMatches('/\b\d{1,3}\b/u', ' ')
            ->replaceMatches('/[^a-z0-9]+/u', ' ')
            ->squish()
            ->value();
    }

    protected function bestMatch(string $lessonName, Collection $mediaFiles): ?array
    {
        $normalizedLesson = $this->normalizeTitle($lessonName);

        if ($normalizedLesson === '' || $mediaFiles->isEmpty()) {
            return null;
        }

        return $mediaFiles
            ->map(function (array $file) use ($normalizedLesson): array {
                $file['confidence'] = $this->confidence($normalizedLesson, $file['normalized_name']);

                return $file;
            })
            ->sortByDesc('confidence')
            ->first();
    }

    protected function confidence(string $lesson, string $media): float
    {
        if ($lesson === '' || $media === '') {
            return 0.0;
        }

        if ($lesson === $media) {
            return 1.0;
        }

        if (Str::contains($media, $lesson) || Str::contains($lesson, $media)) {
            $shorter = min(mb_strlen($lesson), mb_strlen($media));
            $longer = max(mb_strlen($lesson), mb_strlen($media));

            return $longer > 0 ? max(0.82, $shorter / $longer) : 0.0;
        }

        similar_text($lesson, $media, $similarity);

        $distance = levenshtein($lesson, $media);
        $maxLength = max(mb_strlen($lesson), mb_strlen($media), 1);
        $levenshteinScore = max(0, 1 - ($distance / $maxLength));

        return round(max($similarity / 100, $levenshteinScore), 4);
    }

    protected function normalizeMediaFile(array $file): array
    {
        $name = (string) ($file['name'] ?? $file['filename'] ?? $file['title'] ?? '');

        return [
            'id' => $file['id'] ?? $file['file_id'] ?? null,
            'name' => $name,
            'normalized_name' => $this->normalizeTitle($name),
            'mime_type' => $file['mime_type'] ?? $file['mimeType'] ?? null,
            'web_url' => $file['web_url'] ?? $file['webViewLink'] ?? $file['url'] ?? null,
            'source' => $file['source'] ?? 'google_drive',
        ];
    }

    protected function clearMediaFields(array $lesson): array
    {
        return collect($lesson)
            ->except([
                'media_source',
                'media_file_id',
                'media_name',
                'media_mime_type',
                'media_url',
                'media_matched_at',
                'published_at',
            ])
            ->all();
    }
}
