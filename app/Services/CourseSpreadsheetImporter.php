<?php

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyTrack;
use Illuminate\Support\Facades\DB;

class CourseSpreadsheetImporter
{
    public function __construct(
        protected CourseSpreadsheetParser $parser,
    ) {}

    public function import(string $path): Course
    {
        $payload = $this->parser->parse($path);

        return DB::transaction(function () use ($payload) {
            $course = Course::updateOrCreate(
                ['slug' => $payload['course_slug']],
                [
                    'name' => $payload['course_name'],
                    'description' => 'Curso importado por planilha.',
                    'is_active' => true,
                ],
            );

            $this->importStructure($course, $payload, $payload['study_track_name']);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }

    public function importInto(Course $course, string $path): Course
    {
        $payload = $this->parser->parse($path);

        return DB::transaction(function () use ($course, $payload) {
            $this->importStructure($course, $payload, 'Trilha Oficial - ' . $course->name, false);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }

    protected function importStructure(Course $course, array $payload, string $studyTrackName, bool $replaceTrackModules = true): void
    {
        $moduleIds = [];

        foreach ($payload['modules'] as $moduleData) {
            $module = CourseModule::updateOrCreate(
                [
                    'course_id' => $course->id,
                    'name' => $moduleData['name'],
                ],
                [
                    'type' => $moduleData['type'],
                    'lessons' => $moduleData['lessons'] ?? [],
                    'workload_minutes' => $moduleData['workload_minutes'],
                    'sort_order' => $moduleData['sort_order'],
                    'is_active' => true,
                ],
            );

            $moduleIds[$module->id] = [
                'weight' => 1,
                'sort_order' => $moduleData['sort_order'],
            ];
        }

        $studyTrack = StudyTrack::updateOrCreate(
            [
                'course_id' => $course->id,
                'name' => $studyTrackName,
            ],
            [
                'description' => 'Trilha oficial gerada automaticamente a partir da planilha do curso.',
                'is_active' => true,
            ],
        );

        if ($replaceTrackModules) {
            $studyTrack->modules()->sync($moduleIds);

            return;
        }

        $studyTrack->modules()->syncWithoutDetaching($moduleIds);
    }
}
