<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\Video;
use App\Services\LessonCourseLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LessonCourseLinkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_ready_standalone_lesson_to_course_structure_by_approximate_title(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Informática']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Word 2016',
            'slug' => 'word-2016',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $placeholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Word 2016',
            'slug' => '01-word-2016-placeholder',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'draft',
            'source_status' => 'awaiting_media',
        ]);

        $readyLesson = Lesson::query()->create([
            'title' => '01-word-2016.mp4',
            'slug' => Str::slug('01-word-2016.mp4'),
            'type' => 'video',
            'duration_seconds' => 682,
            'sort_order' => 1,
            'status' => 'draft',
            'panda_video_id' => 'panda-video-123',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'Informática / OFFICE 2016 - NOVAS / 01 - Word 2016',
            ],
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(1, $stats['linked']);
        $this->assertSame(1, $stats['replaced']);
        $this->assertSame(1, $stats['published']);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $placeholder->id,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $readyLesson->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $placeholder->id,
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'status' => 'archived',
        ]);
    }

    public function test_it_replaces_lei_organica_placeholders_with_ready_abbreviated_lessons_by_contextual_number(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Conhecimentos Básicos de Legislação Municipal']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Lei Orgânica - Santos',
            'slug' => 'lei-organica-santos',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $firstPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '1 - Lei Orgânica - Santos',
            'slug' => '1-lei-organica-santos-placeholder',
            'type' => 'video',
            'duration_seconds' => 60,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $secondPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '2 - Lei Orgânica - Santos',
            'slug' => '2-lei-organica-santos-placeholder',
            'type' => 'video',
            'duration_seconds' => 60,
            'sort_order' => 2,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $firstReadyLesson = Lesson::query()->create([
            'title' => '1 - Lei Orgânica - Santos',
            'slug' => '1-lei-organica-santos-ready',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-lo-santos-1',
            'source_status' => 'media_ready',
        ]);
        $secondReadyLesson = Lesson::query()->create([
            'title' => '03 - Lo Santos 2',
            'slug' => '03-lo-santos-2-ready',
            'type' => 'video',
            'duration_seconds' => 1140,
            'sort_order' => 3,
            'status' => 'published',
            'panda_video_id' => 'panda-lo-santos-2',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'Conhecimentos Básicos de Legislação Municipal / Lei Orgânica Santos',
            ],
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(2, $stats['linked']);
        $this->assertSame(2, $stats['replaced']);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $firstReadyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $secondReadyLesson->id,
            'sort_order' => 2,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $firstPlaceholder->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $secondPlaceholder->id,
        ]);
    }

    public function test_it_replaces_generic_eca_placeholders_with_ready_lessons_from_matching_folder_context(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Curitiba']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Legislação']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Estatuto da Criança e do Adolescente',
            'slug' => 'estatuto-da-crianca-e-do-adolescente',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $firstPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - ECA.',
            'slug' => '01-eca-placeholder',
            'type' => 'video',
            'duration_seconds' => 3480,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $fifthPlaceholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '5 - ECA.',
            'slug' => '5-eca-placeholder',
            'type' => 'video',
            'duration_seconds' => 2100,
            'sort_order' => 5,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);

        $readyLessons = collect([
            ['01 - Disposições Preliminares', 'panda-eca-01', 1],
            ['05 - Eca Parte 1', 'panda-eca-05-1', 5],
            ['05 - Eca Parte 2', 'panda-eca-05-2', 5],
            ['05 - Eca Parte 3', 'panda-eca-05-3', 5],
        ])->map(fn (array $payload): Lesson => Lesson::query()->create([
            'title' => $payload[0],
            'slug' => Str::slug($payload[0]),
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => $payload[2],
            'status' => 'published',
            'panda_video_id' => $payload[1],
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'ECA + LDB',
            ],
        ]));
        $ldbLesson = Lesson::query()->create([
            'title' => '02 - Ldb',
            'slug' => '02-ldb',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 2,
            'status' => 'published',
            'panda_video_id' => 'panda-ldb-02',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'ECA + LDB',
            ],
        ]);
        $existingEcaLesson = Lesson::query()->create([
            'title' => '09 - Eca',
            'slug' => '09-eca',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 9,
            'status' => 'published',
            'panda_video_id' => 'panda-eca-09',
            'source_status' => 'media_ready',
        ]);
        $track->lessons()->syncWithoutDetaching([
            $ldbLesson->id => ['sort_order' => 2],
            $existingEcaLesson->id => ['sort_order' => 9],
        ]);
        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $ldbLesson->id,
            'sort_order' => 2,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $existingEcaLesson->id,
            'sort_order' => 9,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(2, $stats['linked']);
        $this->assertSame(2, $stats['replaced']);
        $readyLessons->take(2)->each(function (Lesson $lesson) use ($module, $track): void {
            $this->assertDatabaseHas('course_module_lessons', [
                'course_module_id' => $module->id,
                'lesson_id' => $lesson->id,
            ]);
            $this->assertDatabaseHas('course_module_track_lessons', [
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
            ]);
        });
        $readyLessons->skip(2)->each(function (Lesson $lesson) use ($track): void {
            $this->assertDatabaseMissing('course_module_track_lessons', [
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
            ]);
        });
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $firstPlaceholder->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $fifthPlaceholder->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $ldbLesson->id,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $existingEcaLesson->id,
        ]);
    }

    public function test_it_links_ready_standalone_lesson_to_module_planning_lesson_by_approximate_name_without_placeholder(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Português',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Classes de Palavras - Conjunção Subordinativa Adverbial', 'minutes' => 16],
                ['name' => 'Pontuação - Parte 01', 'minutes' => 31],
            ],
            'workload_minutes' => 47,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);
        $readyLesson = Lesson::query()->create([
            'title' => 'Aula 03 - Classes de Palavras Conjuncao Subordinativa Adverbial.mp4',
            'slug' => 'aula-03-classes-de-palavras-conjuncao-subordinativa-adverbial',
            'type' => 'video',
            'duration_seconds' => 960,
            'sort_order' => 99,
            'status' => 'draft',
            'panda_video_id' => 'panda-classes-palavras-03',
            'source_status' => 'media_ready',
            'metadata' => [
                'drive_source_folder_path' => 'Aulas avulsas / Português',
            ],
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(1, $stats['linked']);
        $this->assertSame(1, $stats['published']);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $readyLesson->id,
            'status' => 'published',
        ]);
    }

    public function test_it_does_not_use_module_planning_fallback_when_course_has_scoped_track_lessons(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Curitiba']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Legislação',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Princípios fundamentais - Parte 01', 'minutes' => 17],
                ['name' => '01 - Lei Federal nº 13.460/2017.', 'minutes' => 32],
            ],
            'is_active' => true,
        ]);
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);

        $principlesTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Princípios Fundamentais',
            'slug' => 'principios-fundamentais',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $lawTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Lei Federal nº 13.460/2017',
            'slug' => 'lei-federal-no-13460-2017',
            'sort_order' => 2,
            'status' => 'published',
        ]);
        $principlesTrack->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);
        $lawTrack->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 2]]);

        $placeholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $principlesTrack->id,
            'title' => 'Princípios fundamentais - Parte 01',
            'slug' => 'principios-fundamentais-parte-01',
            'type' => 'video',
            'duration_seconds' => 17 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $readyLawLesson = Lesson::query()->create([
            'title' => '01 - Lei Federal nº 13.460/2017.',
            'slug' => '01-lei-federal-no-13460-2017',
            'type' => 'video',
            'duration_seconds' => 32 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-lei-federal-13460-01',
            'source_status' => 'media_ready',
            'metadata' => [
                'library_folder_path' => 'Conhecimentos Específicos / Lei Federal nº 13.460/2017',
            ],
        ]);

        $principlesTrack->lessons()->syncWithoutDetaching([
            $placeholder->id => ['sort_order' => 1],
        ]);
        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $principlesTrack->id,
            'lesson_id' => $placeholder->id,
            'sort_order' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(LessonCourseLinker::class)->sync($course);

        $this->assertDatabaseMissing('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $principlesTrack->id,
            'lesson_id' => $readyLawLesson->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $lawTrack->id,
            'lesson_id' => $readyLawLesson->id,
        ]);
    }

    public function test_it_removes_ready_lesson_from_unrelated_track_context(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Curitiba']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Legislação',
            'is_active' => true,
        ]);
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);

        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Princípios Fundamentais',
            'slug' => 'principios-fundamentais',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $wrongLesson = Lesson::query()->create([
            'title' => '01 - Lei Federal nº 13.460/2017.',
            'slug' => '01-lei-federal-no-13460-2017',
            'type' => 'video',
            'duration_seconds' => 32 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-lei-federal-13460-01',
            'source_status' => 'media_ready',
            'metadata' => [
                'library_folder_path' => 'Conhecimentos Específicos / Lei Federal nº 13.460/2017',
            ],
        ]);
        $track->lessons()->syncWithoutDetaching([
            $wrongLesson->id => ['sort_order' => 1],
        ]);
        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $wrongLesson->id,
            'sort_order' => 1,
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(LessonCourseLinker::class)->sync($course);

        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $wrongLesson->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $wrongLesson->id,
        ]);
    }

    public function test_it_preserves_spreadsheet_track_structure_without_extra_or_missing_lessons(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Curitiba']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Legislação',
            'is_active' => true,
        ]);
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);

        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Poder Executivo',
            'slug' => 'poder-executivo',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $officialFirst = Lesson::query()->create([
            'title' => 'Poder Executivo - Parte 01',
            'slug' => 'poder-executivo-parte-01',
            'type' => 'video',
            'duration_seconds' => 21 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $officialSecond = Lesson::query()->create([
            'title' => 'Poder Executivo - Parte 02',
            'slug' => 'poder-executivo-parte-02',
            'type' => 'video',
            'duration_seconds' => 21 * 60,
            'sort_order' => 2,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $extraReady = Lesson::query()->create([
            'title' => 'Poder Legislativo - Parte 01',
            'slug' => 'poder-legislativo-parte-01',
            'type' => 'video',
            'duration_seconds' => 21 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-legislativo-01',
            'source_status' => 'media_ready',
        ]);
        $extraPending = Lesson::query()->create([
            'title' => 'Aula extra pendente',
            'slug' => 'aula-extra-pendente',
            'type' => 'video',
            'duration_seconds' => 10 * 60,
            'sort_order' => 3,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);

        $track->lessons()->syncWithoutDetaching([
            $officialFirst->id => ['sort_order' => 1],
            $officialSecond->id => ['sort_order' => 2],
            $extraReady->id => ['sort_order' => 1],
            $extraPending->id => ['sort_order' => 3],
        ]);

        foreach ([[$officialFirst, 1], [$officialSecond, 2]] as [$lesson, $sortOrder]) {
            DB::table('course_module_track_lesson_course')->insert([
                'course_id' => $course->id,
                'course_module_track_id' => $track->id,
                'lesson_id' => $lesson->id,
                'sort_order' => $sortOrder,
                'status' => 'published',
                'metadata' => json_encode(['source' => 'spreadsheet']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $extraReady->id,
            'sort_order' => 1,
            'status' => 'published',
            'metadata' => json_encode(['source' => 'lesson_course_linker']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_module_track_lesson_course')->insert([
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $extraPending->id,
            'sort_order' => 3,
            'status' => 'published',
            'metadata' => json_encode(['source' => 'legacy']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(0, $stats['linked']);
        $this->assertSame(0, $stats['replaced']);
        $this->assertDatabaseHas('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $officialFirst->id,
        ]);
        $this->assertDatabaseHas('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $officialSecond->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $extraReady->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $extraPending->id,
        ]);
        $this->assertSame([
            'Poder Executivo - Parte 01',
            'Poder Executivo - Parte 02',
        ], DB::table('course_module_track_lesson_course as scope')
            ->join('lessons', 'lessons.id', '=', 'scope.lesson_id')
            ->where('scope.course_id', $course->id)
            ->where('scope.course_module_track_id', $track->id)
            ->orderBy('scope.sort_order')
            ->pluck('lessons.title')
            ->all());
    }

    public function test_it_prefers_ready_lesson_with_closer_duration_when_titles_match(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create(['name' => 'Administração']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Protocolo',
            'slug' => 'protocolo',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);

        $placeholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Protocolo e arquivo administrativo',
            'slug' => '01-protocolo-arquivo-administrativo-placeholder',
            'type' => 'video',
            'duration_seconds' => 30 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $shortLesson = Lesson::query()->create([
            'title' => '01 - Protocolo e arquivo administrativo',
            'slug' => '01-protocolo-arquivo-administrativo-curta',
            'type' => 'video',
            'duration_seconds' => 5 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-protocolo-curta',
            'source_status' => 'media_ready',
        ]);
        $matchingDurationLesson = Lesson::query()->create([
            'title' => '01 - Protocolo e arquivo administrativo',
            'slug' => '01-protocolo-arquivo-administrativo-completa',
            'type' => 'video',
            'duration_seconds' => 29 * 60,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-protocolo-completa',
            'source_status' => 'media_ready',
        ]);

        app(LessonCourseLinker::class)->sync($course);

        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $matchingDurationLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $shortLesson->id,
        ]);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $placeholder->id,
        ]);
    }

    public function test_it_links_ready_lesson_using_linked_video_title(): void
    {
        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Português',
            'type' => 'basic',
            'lessons' => [
                ['name' => 'Classes de Palavras - Conjunção Subordinativa Adverbial', 'minutes' => 16],
            ],
            'workload_minutes' => 16,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);
        $video = Video::query()->create([
            'title' => 'Aula 03 - Classes de Palavras - Conjunção Subordinativa Adverbial.mp4',
            'slug' => 'aula-03-classes-de-palavras-conjuncao-subordinativa-adverbial',
            'provider' => 'panda',
            'provider_video_id' => 'panda-classes-palavras-video',
            'embed_url' => 'https://player.example.com/classes-palavras-video',
            'duration_seconds' => 960,
            'source_status' => 'media_ready',
        ]);
        $readyLesson = Lesson::query()->create([
            'video_id' => $video->id,
            'title' => 'Conjunção',
            'slug' => 'conjuncao',
            'type' => 'video',
            'duration_seconds' => 960,
            'sort_order' => 99,
            'status' => 'draft',
            'source_status' => 'awaiting_media',
        ]);

        $stats = app(LessonCourseLinker::class)->sync($course);

        $this->assertSame(1, $stats['linked']);
        $this->assertSame(1, $stats['published']);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $readyLesson->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('lessons', [
            'id' => $readyLesson->id,
            'status' => 'published',
        ]);
    }
}
