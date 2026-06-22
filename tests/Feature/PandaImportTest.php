<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\AiArtifact;
use App\Models\Lesson;
use App\Models\PandaImportRun;
use App\Services\PandaCourseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PandaImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_panda_folder_import_creates_module_reusable_lesson_and_history(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos*' => Http::response([
                'data' => [
                    [
                        'id' => 'panda-video-1',
                        'title' => 'Aula Panda',
                        'duration_seconds' => 1234,
                        'thumbnail_url' => 'https://cdn.test/thumb.jpg',
                        'video_player' => 'https://player.test/embed/panda-video-1',
                        'status' => 'ready',
                        'summary' => 'Resumo gerado pelo Panda.',
                        'questions' => [
                            ['question' => 'Pergunta 1?', 'answer' => 'A'],
                        ],
                        'folder' => ['id' => 'folder-1', 'name' => 'Português'],
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();

        $run = app(PandaCourseImporter::class)->importFolder($course, 'folder-1', lessonStatus: 'published');

        $module = $course->modules()->where('panda_folder_id', 'folder-1')->firstOrFail();
        $lesson = Lesson::query()->where('panda_video_id', 'panda-video-1')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame('Português', $module->name);
        $this->assertSame('Aula Panda', $lesson->title);
        $this->assertSame(1234, $lesson->duration_seconds);
        $this->assertSame('https://cdn.test/thumb.jpg', $lesson->thumbnail_url);
        $this->assertSame('https://player.test/embed/panda-video-1', $lesson->panda_embed_url);
        $this->assertTrue($module->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertDatabaseHas('ai_artifacts', [
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'summary',
            'provider' => 'panda',
        ]);
        $this->assertDatabaseHas('ai_artifacts', [
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'quiz',
            'provider' => 'panda',
        ]);
        $this->assertDatabaseHas('panda_import_items', [
            'panda_import_run_id' => $run->id,
            'external_type' => 'video',
            'external_id' => 'panda-video-1',
            'local_type' => 'lesson',
            'local_id' => $lesson->id,
            'status' => 'created',
        ]);
    }

    public function test_panda_import_reuses_same_lesson_across_courses(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos*' => Http::response([
                'data' => [
                    [
                        'id' => 'shared-video',
                        'title' => 'Aula Compartilhada',
                        'duration_seconds' => 600,
                        'embed_url' => 'https://player.test/shared-video',
                    ],
                ],
            ]),
        ]);

        $firstCourse = Course::factory()->create();
        $secondCourse = Course::factory()->create();

        app(PandaCourseImporter::class)->importFolder($firstCourse, 'folder-a', 'Módulo A', 'published');
        app(PandaCourseImporter::class)->importFolder($secondCourse, 'folder-b', 'Módulo B', 'published');

        $lesson = Lesson::query()->where('panda_video_id', 'shared-video')->firstOrFail();

        $this->assertSame(1, Lesson::query()->where('panda_video_id', 'shared-video')->count());
        $this->assertSame(2, $lesson->modules()->count());
        $this->assertSame(2, PandaImportRun::query()->where('status', 'finished')->count());
        $this->assertTrue($firstCourse->modules()->first()->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertTrue($secondCourse->modules()->first()->onlineLessons()->whereKey($lesson->id)->exists());
    }

    public function test_panda_folder_import_runs_from_existing_module(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos*' => Http::response([
                'data' => [
                    [
                        'id' => 'module-video',
                        'title' => 'Aula no módulo',
                        'duration_seconds' => 900,
                        'embed_url' => 'https://player.test/module-video',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Módulo reutilizável',
        ]);

        $run = app(PandaCourseImporter::class)->importIntoModule($module, 'folder-module', 'published');

        $lesson = Lesson::query()->where('panda_video_id', 'module-video')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame('folder-module', $module->fresh()->panda_folder_id);
        $this->assertTrue($module->fresh()->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertSame(1, $course->fresh()->modules()->whereKey($module->id)->count());
    }

    public function test_panda_import_replaces_existing_module_with_same_name(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos*' => Http::response([
                'data' => [
                    [
                        'id' => 'replace-video',
                        'title' => 'Aula substituta',
                        'duration_seconds' => 1200,
                        'embed_url' => 'https://player.test/replace-video',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();
        $existingModule = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português - Classe de palavras',
            'workload_minutes' => 10,
        ]);

        $run = app(PandaCourseImporter::class)->importReplacingModuleByName(
            $course,
            'Portugues - classe de palavras',
            'folder-replace',
            'published',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'replace-video')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame(1, CourseModule::query()->where('name', 'Português - Classe de palavras')->count());
        $this->assertSame('folder-replace', $existingModule->fresh()->panda_folder_id);
        $this->assertSame(20, $existingModule->fresh()->workload_minutes);
        $this->assertTrue($existingModule->fresh()->onlineLessons()->whereKey($lesson->id)->exists());
    }
}
