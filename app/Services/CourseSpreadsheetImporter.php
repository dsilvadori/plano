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
        protected StudyPlanGenerator $studyPlanGenerator,
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
            $this->refreshActiveStudyPlans($course);

            return $course->fresh(['modules', 'studyTracks.modules']);
        });
    }

    public function importInto(Course $course, string $path): Course
    {
        $payload = $this->parser->parse($path);

        return DB::transaction(function () use ($course, $payload) {
            $studyTrackName = $this->resolveOfficialStudyTrackName($course) ?? 'Trilha Oficial - ' . $course->name;

            $this->importStructure($course, $payload, $studyTrackName);
            $this->refreshActiveStudyPlans($course);

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
            $staleModuleIds = $course->modules()
                ->whereNotIn('id', array_keys($moduleIds))
                ->pluck('id')
                ->all();

            if ($staleModuleIds !== []) {
                CourseModule::query()
                    ->whereKey($staleModuleIds)
                    ->delete();
            }

            return;
        }

        $studyTrack->modules()->syncWithoutDetaching($moduleIds);
    }

    protected function resolveOfficialStudyTrackName(Course $course): ?string
    {
        return $course->studyTracks()
            ->where('name', 'like', 'Trilha Oficial -%')
            ->orderBy('id')
            ->value('name');
    }

    protected function refreshActiveStudyPlans(Course $course): void
    {
        $course->studyPlans()
            ->where('status', 'active')
            ->with(['course', 'studyTrack', 'user'])
            ->get()
            ->each(function ($studyPlan): void {
                $this->studyPlanGenerator->regenerate(
                    $studyPlan,
                    $studyPlan->course,
                    $studyPlan->studyTrack,
                    $studyPlan->exam_date_confirmed ? $studyPlan->exam_date?->toDateString() : null,
                    $studyPlan->start_date?->toDateString() ?? now()->toDateString(),
                    $studyPlan->available_days ?? [],
                    $studyPlan->available_minutes_by_day ?? [],
                    $studyPlan->intensity ?: 'balanced',
                );
            });
    }
}
