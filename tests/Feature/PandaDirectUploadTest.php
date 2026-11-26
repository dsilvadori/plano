<?php

namespace Tests\Feature;

use App\Filament\Resources\Lessons\Pages\EditLesson;
use App\Filament\Resources\Lessons\Pages\ListLessons;
use App\Jobs\UploadDirectVideoToPanda;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Services\LessonDirectVideoUploader;
use App\Services\PandaVideoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PandaDirectUploadTest extends TestCase
{
    use RefreshDatabase;

    protected const FOLDER_ID = 'b96cfc31-771f-4237-b5ca-015edb0e5a3b';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.video_upload_mode' => 'multipart',
            'services.panda.video_upload_path' => '/videos',
            'services.panda.direct_upload_disk' => 'local',
            'services.panda.direct_upload_directory' => 'panda-uploads',
        ]);
    }

    public function test_queue_stores_upload_metadata_and_dispatches_job(): void
    {
        Queue::fake();
        $lesson = $this->lessonWithoutMedia();
        Storage::disk('local')->put('panda-uploads/aula.mp4', 'fake-video');

        $lesson = app(LessonDirectVideoUploader::class)->queue(
            $lesson,
            'panda-uploads/aula.mp4',
            'Aula 01.mp4',
            'https://dashboard.pandavideo.com.br/#/folders/'.self::FOLDER_ID,
        );

        $this->assertSame('upload_queued', $lesson->source_status);
        $this->assertSame('video', $lesson->type);
        $this->assertSame(self::FOLDER_ID, $lesson->metadata['panda_folder_id']);
        $this->assertSame('queued', $lesson->metadata['panda_direct_upload']['status']);
        $this->assertSame('panda-uploads/aula.mp4', $lesson->metadata['panda_direct_upload']['path']);
        $this->assertSame('Aula 01.mp4', $lesson->metadata['panda_direct_upload']['original_name']);
        $this->assertSame($lesson->title, $lesson->metadata['panda_direct_upload']['title']);

        Queue::assertPushed(UploadDirectVideoToPanda::class, fn (UploadDirectVideoToPanda $job): bool => $job->lessonId === $lesson->id
            && $job->path === 'panda-uploads/aula.mp4');
    }

    public function test_queue_blocks_upload_to_panda_root_and_discards_file(): void
    {
        Queue::fake();
        $lesson = $this->lessonWithoutMedia();
        Storage::disk('local')->put('panda-uploads/aula.mp4', 'fake-video');

        try {
            app(LessonDirectVideoUploader::class)->queue($lesson, 'panda-uploads/aula.mp4', 'aula.mp4', '');
            $this->fail('O envio sem pasta deveria ser bloqueado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('raiz do Panda', $exception->getMessage());
        }

        Storage::disk('local')->assertMissing('panda-uploads/aula.mp4');
        Queue::assertNothingPushed();
        $this->assertSame('awaiting_media', $lesson->fresh()->source_status);
    }

    public function test_queue_keeps_current_media_status_when_replacing_a_video(): void
    {
        Queue::fake();
        $lesson = $this->lessonWithoutMedia([
            'panda_video_id' => 'old-video',
            'panda_embed_url' => 'https://player.test/old-video',
            'source_status' => 'media_ready',
        ]);
        Storage::disk('local')->put('panda-uploads/nova.mp4', 'fake-video');

        $lesson = app(LessonDirectVideoUploader::class)->queue($lesson, 'panda-uploads/nova.mp4', 'nova.mp4', self::FOLDER_ID);

        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame('old-video', $lesson->panda_video_id);
        $this->assertSame('old-video', $lesson->metadata['panda_direct_upload']['previous_panda_video_id']);
    }

    public function test_job_uploads_file_and_reflects_ready_video_on_lesson_and_library(): void
    {
        Http::fake([
            'panda.test/videos' => Http::response([
                'id' => 'uploaded-video-1',
                'title' => 'Aula enviada',
                'status' => 'CONVERTED',
                'duration' => 1260,
                'embed_url' => 'https://player.test/uploaded-video-1',
                'thumbnail' => 'https://thumb.test/uploaded-video-1.jpg',
                'folder_id' => self::FOLDER_ID,
            ]),
        ]);

        [$lesson, $job] = $this->queuedUpload();

        $job->handle(app(PandaVideoClient::class));

        $lesson->refresh();

        $this->assertSame('uploaded-video-1', $lesson->panda_video_id);
        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame(1260, (int) $lesson->duration_seconds);
        $this->assertSame('https://thumb.test/uploaded-video-1.jpg', $lesson->thumbnail_url);
        $this->assertSame('uploaded', $lesson->metadata['panda_direct_upload']['status']);
        $this->assertSame('uploaded-video-1', $lesson->metadata['panda_direct_upload']['panda_video_id']);
        Storage::disk('local')->assertMissing('panda-uploads/aula.mp4');

        $video = Video::query()->where('provider_video_id', 'uploaded-video-1')->first();
        $this->assertNotNull($video, 'A Biblioteca de Vídeos deve refletir o vídeo enviado.');
        $this->assertSame($video->id, (int) $lesson->video_id);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://panda.test/videos'
            && $request->isMultipart());
    }

    public function test_job_marks_lesson_as_processing_while_panda_converts(): void
    {
        Http::fake([
            'panda.test/videos' => Http::response([
                'id' => 'uploaded-video-2',
                'status' => 'PROCESSING',
                'folder_id' => self::FOLDER_ID,
            ]),
        ]);

        [$lesson, $job] = $this->queuedUpload();

        $job->handle(app(PandaVideoClient::class));

        $lesson->refresh();

        $this->assertSame('uploaded-video-2', $lesson->panda_video_id);
        $this->assertSame('panda_processing', $lesson->source_status);
        $this->assertNotNull($lesson->metadata['panda_status_sync_queued_at']);
    }

    public function test_job_keeps_file_and_requeues_on_temporary_panda_error(): void
    {
        Http::fake([
            'panda.test/videos' => Http::response(['message' => 'Too many requests'], 429),
        ]);

        [$lesson, $job] = $this->queuedUpload();

        $job->handle(app(PandaVideoClient::class));

        $lesson->refresh();

        $this->assertSame('upload_queued', $lesson->source_status);
        $this->assertSame('queued', $lesson->metadata['panda_direct_upload']['status']);
        $this->assertStringContainsString('429', $lesson->metadata['panda_direct_upload']['error']);
        Storage::disk('local')->assertExists('panda-uploads/aula.mp4');
    }

    public function test_job_marks_failure_and_discards_file_on_permanent_error(): void
    {
        Http::fake([
            'panda.test/videos' => Http::response(['message' => 'Invalid file'], 422),
        ]);

        [$lesson, $job] = $this->queuedUpload();

        try {
            $job->handle(app(PandaVideoClient::class));
            $this->fail('Erro definitivo do Panda deveria ser propagado.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('422', $exception->getMessage());
        }

        $lesson->refresh();

        $this->assertSame('upload_failed', $lesson->source_status);
        $this->assertSame('failed', $lesson->metadata['panda_direct_upload']['status']);
        Storage::disk('local')->assertMissing('panda-uploads/aula.mp4');
    }

    public function test_job_ignores_upload_replaced_by_a_newer_one(): void
    {
        Http::fake();

        [$lesson, $job] = $this->queuedUpload();
        Storage::disk('local')->put('panda-uploads/antigo.mp4', 'old-video');
        $staleJob = new UploadDirectVideoToPanda($lesson->id, 'local', 'panda-uploads/antigo.mp4');

        $staleJob->handle(app(PandaVideoClient::class));

        Http::assertNothingSent();
        Storage::disk('local')->assertMissing('panda-uploads/antigo.mp4');
        Storage::disk('local')->assertExists('panda-uploads/aula.mp4');
        $this->assertSame('upload_queued', $lesson->fresh()->source_status);
    }

    public function test_default_folder_comes_from_lesson_track(): void
    {
        $lesson = $this->lessonWithoutMedia();
        $module = CourseModule::factory()->create(['panda_folder_id' => 'module-folder']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Trilha',
            'slug' => 'trilha',
            'sort_order' => 1,
            'status' => 'published',
            'panda_folder_id' => 'track-folder',
        ]);
        $lesson->tracks()->attach($track->id, ['sort_order' => 1]);

        $this->assertSame('track-folder', app(LessonDirectVideoUploader::class)->defaultFolderReferenceFor($lesson));
    }

    public function test_prune_command_removes_only_stale_files_not_waiting_for_upload(): void
    {
        Queue::fake();
        [$lesson] = $this->queuedUpload();
        Storage::disk('local')->put('panda-uploads/orfao.mp4', 'orphan');
        Storage::disk('local')->put('panda-uploads/recente.mp4', 'recent');

        $old = now()->subDays(5)->getTimestamp();
        touch(Storage::disk('local')->path('panda-uploads/orfao.mp4'), $old);
        touch(Storage::disk('local')->path('panda-uploads/aula.mp4'), $old);

        $this->artisan('panda:prune-direct-uploads')->assertSuccessful();

        Storage::disk('local')->assertMissing('panda-uploads/orfao.mp4');
        Storage::disk('local')->assertExists('panda-uploads/recente.mp4');
        Storage::disk('local')->assertExists('panda-uploads/aula.mp4');
    }

    public function test_admin_uploads_video_from_lesson_edit_page(): void
    {
        Queue::fake();
        $lesson = $this->lessonWithoutMedia();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditLesson::class, ['record' => $lesson->getRouteKey()])
            ->callAction('uploadPandaVideo', data: [
                'video_file' => UploadedFile::fake()->create('aula-01.mp4', 2048, 'video/mp4'),
                'panda_folder_reference' => self::FOLDER_ID,
                'panda_title' => 'Aula 01 - Título no Panda',
            ])
            ->assertHasNoActionErrors();

        $lesson->refresh();

        $this->assertSame('upload_queued', $lesson->source_status);
        $this->assertSame('Aula 01 - Título no Panda', $lesson->metadata['panda_direct_upload']['title']);
        $this->assertSame('aula-01.mp4', $lesson->metadata['panda_direct_upload']['original_name']);
        Storage::disk('local')->assertExists($lesson->metadata['panda_direct_upload']['path']);
        Queue::assertPushed(UploadDirectVideoToPanda::class);
    }

    public function test_admin_creates_lesson_from_video_in_selected_track(): void
    {
        Queue::fake();
        $module = CourseModule::factory()->create(['course_id' => null, 'name' => 'Português']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Crase',
            'slug' => 'crase',
            'sort_order' => 1,
            'status' => 'published',
            'panda_folder_id' => self::FOLDER_ID,
        ]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('createLessonFromVideo', data: [
                'title' => '01 - Crase obrigatória',
                'course_module_id' => $module->id,
                'course_module_track_id' => $track->id,
                'lesson_status' => 'published',
                'video_file' => UploadedFile::fake()->create('crase.mp4', 2048, 'video/mp4'),
            ])
            ->assertHasNoActionErrors();

        $lesson = Lesson::query()->where('title', '01 - Crase obrigatória')->firstOrFail();

        $this->assertSame('published', $lesson->status);
        $this->assertSame('upload_queued', $lesson->source_status);
        $this->assertSame(self::FOLDER_ID, $lesson->metadata['panda_folder_id'], 'Sem pasta informada, usa a pasta Panda da subpasta.');
        $this->assertTrue($track->lessons()->whereKey($lesson->id)->exists());
        $this->assertSame($track->id, (int) $lesson->course_module_track_id);
        Queue::assertPushed(UploadDirectVideoToPanda::class, fn (UploadDirectVideoToPanda $job): bool => $job->lessonId === $lesson->id);
    }

    public function test_admin_bulk_uploads_folder_creating_lessons_in_file_name_order(): void
    {
        Queue::fake();
        $module = CourseModule::factory()->create(['course_id' => null, 'name' => 'Português']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Crase',
            'slug' => 'crase',
            'sort_order' => 1,
            'status' => 'published',
            'panda_folder_id' => self::FOLDER_ID,
        ]);
        $existing = $this->lessonWithoutMedia(['title' => '02 - Casos proibidos']);
        $existing->tracks()->attach($track->id, ['sort_order' => 1]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('bulkUploadVideosToPanda', data: [
                'video_files' => [
                    UploadedFile::fake()->create('10 - Revisão.mp4', 1024, 'video/mp4'),
                    UploadedFile::fake()->create('02 - Casos proibidos.mp4', 1024, 'video/mp4'),
                    UploadedFile::fake()->create('01_Introducao.mov', 1024, 'video/quicktime'),
                    UploadedFile::fake()->create('3 - Exercícios.mp4', 1024, 'video/mp4'),
                ],
                'course_module_id' => $module->id,
                'course_module_track_id' => $track->id,
                'lesson_status' => 'published',
                'skip_existing_titles' => true,
            ])
            ->assertHasNoActionErrors();

        $created = $track->lessons()
            ->whereKeyNot($existing->id)
            ->orderByPivot('sort_order')
            ->get();

        $this->assertSame(['01 Introducao', '3 - Exercícios', '10 - Revisão'], $created->pluck('title')->all());
        $this->assertSame([2, 3, 4], $created->pluck('pivot.sort_order')->map(fn ($order): int => (int) $order)->all());
        $this->assertSame(1, Lesson::query()->where('title', '02 - Casos proibidos')->count(), 'Título repetido é pulado.');

        foreach ($created as $lesson) {
            $this->assertSame('published', $lesson->status);
            $this->assertSame('upload_queued', $lesson->source_status);
            $this->assertSame(self::FOLDER_ID, $lesson->metadata['panda_folder_id']);
            Storage::disk('local')->assertExists($lesson->metadata['panda_direct_upload']['path']);
        }

        $this->assertSame('01_Introducao.mov', $created->first()->metadata['panda_direct_upload']['original_name']);
        Queue::assertPushed(UploadDirectVideoToPanda::class, 3);
        $this->assertCount(3, Storage::disk('local')->files('panda-uploads'), 'O arquivo pulado é apagado.');
    }

    public function test_bulk_upload_without_panda_folder_creates_nothing_and_discards_files(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('bulkUploadVideosToPanda', data: [
                'video_files' => [
                    UploadedFile::fake()->create('aula-1.mp4', 1024, 'video/mp4'),
                    UploadedFile::fake()->create('aula-2.mp4', 1024, 'video/mp4'),
                ],
                'lesson_status' => 'draft',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Lesson::query()->count());
        $this->assertSame([], Storage::disk('local')->files('panda-uploads'));
        Queue::assertNothingPushed();
    }

    public function test_bulk_upload_without_panda_folder_does_not_create_new_folder_or_subfolder(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('bulkUploadVideosToPanda', data: [
                'video_files' => [UploadedFile::fake()->create('aula-1.mp4', 1024, 'video/mp4')],
                'course_module_id' => '__new_module__:Pasta Nova',
                'course_module_track_id' => '__new_track__:Subpasta Nova',
                'lesson_status' => 'draft',
            ])
            ->assertHasNoActionErrors();

        $this->assertFalse(CourseModule::query()->where('name', 'Pasta Nova')->exists());
        $this->assertFalse(CourseModuleTrack::query()->where('name', 'Subpasta Nova')->exists());
        $this->assertSame(0, Lesson::query()->count());
    }

    public function test_bulk_upload_discards_lesson_when_video_cannot_be_queued(): void
    {
        Queue::fake();
        $module = CourseModule::factory()->create(['course_id' => null, 'panda_folder_id' => self::FOLDER_ID]);
        $this->partialMock(LessonDirectVideoUploader::class, function ($mock): void {
            $mock->shouldReceive('queue')->andThrow(new RuntimeException('Fila indisponível.'));
        });
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('bulkUploadVideosToPanda', data: [
                'video_files' => [UploadedFile::fake()->create('aula-1.mp4', 1024, 'video/mp4')],
                'course_module_id' => $module->id,
                'lesson_status' => 'draft',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Lesson::query()->count(), 'A aula sem vídeo na fila é descartada.');
        $this->assertSame(0, $module->onlineLessons()->count());
        $this->assertSame([], Storage::disk('local')->files('panda-uploads'));
    }

    public function test_bulk_upload_gives_unique_slugs_to_repeated_titles(): void
    {
        Queue::fake();
        $module = CourseModule::factory()->create(['course_id' => null, 'panda_folder_id' => self::FOLDER_ID]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ListLessons::class)
            ->callAction('bulkUploadVideosToPanda', data: [
                'video_files' => [
                    UploadedFile::fake()->create('Aula.mp4', 1024, 'video/mp4'),
                    UploadedFile::fake()->create('aula.mp4', 1024, 'video/mp4'),
                ],
                'course_module_id' => $module->id,
                'lesson_status' => 'draft',
                'skip_existing_titles' => false,
            ])
            ->assertHasNoActionErrors();

        $lessons = Lesson::query()->orderBy('id')->get();

        $this->assertCount(2, $lessons);
        $this->assertSame(['aula', 'aula-2'], $lessons->pluck('slug')->all());
        $this->assertTrue($lessons->every(fn (Lesson $lesson): bool => (int) $lesson->course_module_id === $module->id));
        Queue::assertPushed(UploadDirectVideoToPanda::class, 2);
    }

    /**
     * @return array{0: Lesson, 1: UploadDirectVideoToPanda}
     */
    protected function queuedUpload(): array
    {
        $lesson = $this->lessonWithoutMedia();
        Storage::disk('local')->put('panda-uploads/aula.mp4', 'fake-video');

        Queue::fake();
        $lesson = app(LessonDirectVideoUploader::class)->queue($lesson, 'panda-uploads/aula.mp4', 'aula.mp4', self::FOLDER_ID);

        return [$lesson, new UploadDirectVideoToPanda($lesson->id, 'local', 'panda-uploads/aula.mp4')];
    }

    protected function lessonWithoutMedia(array $attributes = []): Lesson
    {
        return Lesson::factory()->create(array_merge([
            'course_id' => null,
            'course_module_id' => null,
            'title' => 'Aula enviada',
            'thumbnail_url' => null,
            'duration_seconds' => 0,
            'panda_video_id' => null,
            'panda_embed_url' => null,
            'panda_player_url' => null,
            'source_status' => 'awaiting_media',
        ], $attributes));
    }
}
