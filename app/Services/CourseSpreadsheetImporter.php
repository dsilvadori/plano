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
                    'name' => $payload['study_track_name'],
                ],
                [
                    'description' => 'Trilha oficial gerada automaticamente a partir da planilha do curso.',
                    'is_active' => true,
                ],
            );

            $studyTrack->modules()->sync($moduleIds);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }
}
