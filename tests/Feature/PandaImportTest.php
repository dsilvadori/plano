<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\PandaImportRun;
use App\Services\PandaCourseImporter;
use App\Services\PandaVideoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PandaImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_panda_client_uploads_video_with_create_then_put_flow(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'create_then_put',
            'services.panda.video_create_path' => '/videos',
            'services.panda.video_binary_upload_path' => '/videos/{id}',
            'services.panda.video_title_field' => 'title',
            'services.panda.video_folder_field' => 'folder_id',
            'services.panda.videos_path' => '/videos',
        ]);

        $path = sys_get_temp_dir().'/panda-client-upload-test.mp4';
        file_put_contents($path, 'video-content');

        Http::fake(function ($request) {
            if ($request->method() === 'POST' && $request->url() === 'https://panda.test/videos') {
                $this->assertSame('test-key', $request->header('Authorization')[0] ?? null);
                $this->assertSame([
                    'title' => 'Aula teste',
                    'folder_id' => 'folder-1',
                ], $request->data());

                return Http::response([
                    'id' => 'video-draft-1',
                    'title' => 'Aula teste',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://panda.test/videos/video-draft-1') {
                return Http::response([
                    'id' => 'video-draft-1',
                    'title' => 'Aula teste',
                    'status' => 'PROCESSING',
                    'folder_id' => 'folder-1',
                    'video_player' => 'https://player.test/embed/video-draft-1',
                ], 200);
            }

            return Http::response([], 404);
        });

        try {
            $video = app(PandaVideoClient::class)->uploadVideo($path, 'Aula teste', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertSame('video-draft-1', $video['panda_video_id']);
        $this->assertSame('PROCESSING', $video['panda_status']);
        $this->assertSame('https://player.test/embed/video-draft-1', $video['panda_embed_url']);
    }

    public function test_panda_client_deletes_draft_when_binary_upload_fails(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'create_then_put',
            'services.panda.video_create_path' => '/videos',
            'services.panda.video_binary_upload_path' => '/videos/{id}',
            'services.panda.video_title_field' => 'title',
            'services.panda.video_folder_field' => 'folder_id',
            'services.panda.videos_path' => '/videos',
        ]);

        $path = sys_get_temp_dir().'/panda-client-upload-fail-test.mp4';
        file_put_contents($path, 'video-content');
        $deleted = false;

        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'POST' && $request->url() === 'https://panda.test/videos') {
                return Http::response([
                    'id' => 'video-draft-fail',
                    'title' => 'Aula teste',
                    'status' => 'DRAFT',
                    'folder_id' => null,
                ], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://panda.test/videos/video-draft-fail') {
                return Http::response('HTTP content length exceeded 10485760 bytes.', 413);
            }

            if ($request->method() === 'DELETE' && $request->url() === 'https://panda.test/videos/video-draft-fail') {
                $deleted = true;

                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('endpoint binário configurado');

            app(PandaVideoClient::class)->uploadVideo($path, 'Aula teste', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertTrue($deleted);
    }

    public function test_panda_client_reconciles_created_video_when_binary_endpoint_reports_size_limit(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'create_then_put',
            'services.panda.video_create_path' => '/videos',
            'services.panda.video_binary_upload_path' => '/videos/{id}',
            'services.panda.video_title_field' => 'title',
            'services.panda.video_folder_field' => 'folder_id',
            'services.panda.videos_path' => '/videos',
        ]);

        $path = sys_get_temp_dir().'/panda-client-upload-reconcile-test.mp4';
        file_put_contents($path, 'video-content');
        $deleted = false;

        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'POST' && $request->url() === 'https://panda.test/videos') {
                return Http::response([
                    'id' => 'video-queued-after-413',
                    'title' => 'Aula teste',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://panda.test/videos/video-queued-after-413') {
                return Http::response('HTTP content length exceeded 10485760 bytes.', 413);
            }

            if ($request->method() === 'GET' && $request->url() === 'https://panda.test/videos/video-queued-after-413') {
                return Http::response([
                    'id' => 'video-queued-after-413',
                    'title' => 'Aula teste',
                    'status' => 'CONVERTING',
                    'folder_id' => 'folder-1',
                    'video_player' => 'https://player.test/embed/video-queued-after-413',
                ], 200);
            }

            if ($request->method() === 'DELETE' && $request->url() === 'https://panda.test/videos/video-queued-after-413') {
                $deleted = true;

                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        try {
            $video = app(PandaVideoClient::class)->uploadVideo($path, 'Aula teste', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertFalse($deleted);
        $this->assertSame('video-queued-after-413', $video['panda_video_id']);
        $this->assertSame('CONVERTING', $video['panda_status']);
        $this->assertSame('https://player.test/embed/video-queued-after-413', $video['panda_embed_url']);
    }

    public function test_panda_client_does_not_reuse_deleting_video_by_title(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.videos_path' => '/videos',
            'services.panda.folder_query_param' => 'folder_id',
        ]);

        Http::fake([
            'https://panda.test/videos?folder_id=folder-1' => Http::response([
                'data' => [[
                    'id' => 'deleting-video',
                    'title' => 'Aula teste',
                    'status' => 'DELETING',
                    'folder_id' => 'folder-1',
                ]],
            ], 200),
        ]);

        $this->assertNull(app(PandaVideoClient::class)->findVideoByTitle('Aula teste', 'folder-1'));
    }

    public function test_panda_client_does_not_reconcile_draft_video_after_binary_failure(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'create_then_put',
            'services.panda.video_create_path' => '/videos',
            'services.panda.video_binary_upload_path' => '/videos/{id}',
            'services.panda.video_title_field' => 'title',
            'services.panda.video_folder_field' => 'folder_id',
            'services.panda.videos_path' => '/videos',
        ]);

        $path = sys_get_temp_dir().'/panda-client-upload-draft-test.mp4';
        file_put_contents($path, 'video-content');
        $deleted = false;

        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'POST' && $request->url() === 'https://panda.test/videos') {
                return Http::response([
                    'id' => 'video-still-draft',
                    'title' => 'Aula teste',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ], 201);
            }

            if ($request->method() === 'PUT' && $request->url() === 'https://panda.test/videos/video-still-draft') {
                return Http::response('HTTP content length exceeded 10485760 bytes.', 413);
            }

            if ($request->method() === 'GET' && $request->url() === 'https://panda.test/videos/video-still-draft') {
                return Http::response([
                    'id' => 'video-still-draft',
                    'title' => 'Aula teste',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ], 200);
            }

            if ($request->method() === 'DELETE' && $request->url() === 'https://panda.test/videos/video-still-draft') {
                $deleted = true;

                return Http::response([], 204);
            }

            return Http::response([], 404);
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('endpoint binário configurado');

            app(PandaVideoClient::class)->uploadVideo($path, 'Aula teste', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertTrue($deleted);
    }

    public function test_panda_client_uploads_video_with_tus_uploader_flow(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'tus',
            'services.panda.videos_path' => '/videos',
            'services.panda.uploader_base_url' => 'https://uploader.test',
            'services.panda.uploader_path' => '/files/',
            'services.panda.uploader_auth_scheme' => '',
            'services.panda.uploader_video_lookup_attempts' => 1,
            'services.panda.uploader_video_lookup_delay_seconds' => 0,
        ]);

        $path = sys_get_temp_dir().'/panda-client-tus-upload-test.mp4';
        file_put_contents($path, 'video-content');
        $createdVideoId = null;
        $metadata = [];
        $patched = false;

        Http::fake(function ($request) use (&$createdVideoId, &$metadata, &$patched) {
            if ($request->method() === 'POST' && $request->url() === 'https://uploader.test/files/') {
                $metadata = collect(explode(',', (string) $request->header('Upload-Metadata')[0]))
                    ->filter()
                    ->mapWithKeys(function (string $entry) {
                        [$key, $value] = explode(' ', $entry, 2);

                        return [$key => base64_decode($value)];
                    })
                    ->all();
                $createdVideoId = $metadata['video_id'] ?? null;

                return Http::response('', 201, ['Location' => '/files/upload-1']);
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://uploader.test/files/upload-1') {
                $patched = true;

                return Http::response('', 204);
            }

            if ($request->method() === 'GET' && $createdVideoId && $request->url() === 'https://panda.test/videos/'.$createdVideoId) {
                return Http::response([
                    'id' => $createdVideoId,
                    'title' => 'Aula TUS',
                    'status' => 'CONVERTING',
                    'folder_id' => 'folder-1',
                    'video_player' => 'https://player.test/embed/'.$createdVideoId,
                ]);
            }

            return Http::response([], 404);
        });

        try {
            $video = app(PandaVideoClient::class)->uploadVideo($path, 'Aula TUS', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertTrue($patched);
        $this->assertNotEmpty($createdVideoId);
        $this->assertSame('folder-1', $metadata['folder_id'] ?? null);
        $this->assertSame('direct', $metadata['upload_type'] ?? null);
        $this->assertSame('test-key', $metadata['authorization'] ?? null);
        $this->assertSame($createdVideoId, $video['panda_video_id']);
        $this->assertSame('CONVERTING', $video['panda_status']);
    }

    public function test_panda_client_does_not_return_draft_after_tus_upload(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.video_upload_mode' => 'tus',
            'services.panda.videos_path' => '/videos',
            'services.panda.uploader_base_url' => 'https://uploader.test',
            'services.panda.uploader_path' => '/files/',
            'services.panda.uploader_video_lookup_attempts' => 1,
            'services.panda.uploader_video_lookup_delay_seconds' => 0,
        ]);

        $path = sys_get_temp_dir().'/panda-client-tus-draft-test.mp4';
        file_put_contents($path, 'video-content');
        $createdVideoId = null;

        Http::fake(function ($request) use (&$createdVideoId) {
            if ($request->method() === 'POST' && $request->url() === 'https://uploader.test/files/') {
                $metadata = collect(explode(',', (string) $request->header('Upload-Metadata')[0]))
                    ->filter()
                    ->mapWithKeys(function (string $entry) {
                        [$key, $value] = explode(' ', $entry, 2);

                        return [$key => base64_decode($value)];
                    })
                    ->all();
                $createdVideoId = $metadata['video_id'] ?? null;

                return Http::response('', 201, ['Location' => '/files/upload-1']);
            }

            if ($request->method() === 'PATCH' && $request->url() === 'https://uploader.test/files/upload-1') {
                return Http::response('', 204);
            }

            if ($request->method() === 'GET' && $createdVideoId && $request->url() === 'https://panda.test/videos/'.$createdVideoId) {
                return Http::response([
                    'id' => $createdVideoId,
                    'title' => 'Aula TUS',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ]);
            }

            return Http::response([], 404);
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('DRAFT');

            app(PandaVideoClient::class)->uploadVideo($path, 'Aula TUS', 'folder-1');
        } finally {
            @unlink($path);
        }
    }

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
        $this->assertSame([
            [
                'name' => '01 - Aula Panda',
                'minutes' => 21,
            ],
        ], $module->fresh()->lessons);
        $this->assertSame([
            [
                'name' => '01 - Aula Panda',
                'minutes' => 21,
            ],
        ], $module->fresh()->planning_lessons);
        $this->assertSame('01 - Aula Panda', $lesson->title);
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

    public function test_panda_import_orders_track_lessons_by_numeric_title_prefix(): void
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
                        'id' => 'video-03',
                        'title' => '03 - Principios Arquivisticos.mp4',
                        'duration_seconds' => 1800,
                    ],
                    [
                        'id' => 'video-01',
                        'title' => '01 - Conceitos Iniciais de Arquivologia.mp4',
                        'duration_seconds' => 1680,
                    ],
                    [
                        'id' => 'video-02',
                        'title' => '02_-_Terminologias_Arquivísticas.mp4',
                        'duration_seconds' => 1440,
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();

        app(PandaCourseImporter::class)->importFolder($course, 'folder-order', 'Arquivologia', 'published');

        $module = $course->modules()->where('panda_folder_id', 'folder-order')->firstOrFail();

        $this->assertSame([
            '01 - Conceitos Iniciais de Arquivologia',
            '02 - Terminologias Arquivísticas',
            '03 - Princípios Arquivísticos',
        ], collect($module->fresh()->lessons)->pluck('name')->all());

        $this->assertSame([
            '01 - Conceitos Iniciais de Arquivologia',
            '02 - Terminologias Arquivísticas',
            '03 - Princípios Arquivísticos',
        ], $module->fresh()->onlineLessons()->pluck('title')->all());
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
        $this->assertSame([
            [
                'name' => '01 - Aula no Módulo',
                'minutes' => 15,
            ],
        ], $module->fresh()->lessons);
        $this->assertTrue($module->fresh()->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertSame(1, $course->fresh()->modules()->whereKey($module->id)->count());
    }

    public function test_panda_import_updates_module_type_when_reimporting_existing_module(): void
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
                        'id' => 'basic-video',
                        'title' => 'Classe de palavras',
                        'duration_seconds' => 900,
                        'embed_url' => 'https://player.test/basic-video',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'specific',
        ]);

        app(PandaCourseImporter::class)->importReplacingModuleByName(
            $course,
            'Portugues',
            'folder-basic',
            'published',
            'basic',
        );

        $this->assertSame('basic', $module->fresh()->type);
        $this->assertTrue($course->fresh()->modules()->whereKey($module->id)->exists());
    }

    public function test_module_planning_lessons_fall_back_to_linked_online_lessons(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'lessons' => null,
            'workload_minutes' => 120,
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Aula vinculada',
            'duration_seconds' => 721,
            'sort_order' => 1,
        ]);

        $module->onlineLessons()->sync([
            $lesson->id => ['sort_order' => 1],
        ]);

        $this->assertSame([
            [
                'name' => 'Aula vinculada',
                'minutes' => 13,
            ],
        ], $module->fresh()->planning_lessons);
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
