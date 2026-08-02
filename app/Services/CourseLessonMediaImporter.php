<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use Illuminate\Support\Facades\DB;

class CourseLessonMediaImporter
{
    public function __construct(
        protected LessonMediaMatcher $matcher,
        protected GoogleDriveMediaScanner $driveScanner,
    ) {}

    public function importFromDrive(Course $course, ?string $folderId = null, float $minimumConfidence = 0.72): array
    {
        return $this->importFromMediaFiles(
            $course,
            $this->driveScanner->listMediaFiles($folderId),
            $minimumConfidence,
        );
    }

    public function importFromManifest(Course $course, string $path, float $minimumConfidence = 0.72): array
    {
        $payload = json_decode((string) file_get_contents($path), true);
        $files = array_is_list($payload) ? $payload : ($payload['files'] ?? []);

        return $this->importFromMediaFiles($course, $files, $minimumConfidence);
    }

    public function importFromMediaFiles(Course $course, array $mediaFiles, float $minimumConfidence = 0.72): array
    {
        return DB::transaction(function () use ($course, $mediaFiles, $minimumConfidence): array {
            $summary = [
                'modules' => 0,
                'lessons' => 0,
                'imported' => 0,
                'missing' => 0,
            ];

            $course->modules()
                ->where('course_modules.is_active', true)
                ->orderBy('course_modules.sort_order')
                ->get()
                ->each(function (CourseModule $module) use ($mediaFiles, $minimumConfidence, &$summary): void {
                    $lessons = $module->lessons ?? [];

                    if ($lessons === []) {
                        return;
                    }

                    $matchedLessons = $this->matcher->matchLessons($lessons, $mediaFiles, $minimumConfidence);

                    $module->forceFill([
                        'lessons' => $matchedLessons,
                    ])->save();

                    $summary['modules']++;
                    $summary['lessons'] += count($matchedLessons);
                    $summary['imported'] += collect($matchedLessons)->where('media_status', 'imported')->count();
                    $summary['missing'] += collect($matchedLessons)->where('media_status', 'missing')->count();
                });

            return $summary;
        });
    }
}
