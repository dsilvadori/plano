<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
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

    public function test_panda_client_requests_ai_package_in_brazilian_portuguese_by_default(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.ai_workflow_path' => '/aiworkflow',
            'services.panda.ai_from_lang' => 'pt-BR',
            'services.panda.ai_package_type' => 'ALL_TEXT_ITEMS',
        ]);

        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://panda.test/aiworkflow', $request->url());
            $this->assertSame([
                'video_id' => 'video-123',
                'from_lang' => 'pt-BR',
                'type' => 'ALL_TEXT_ITEMS',
            ], $request->data());

            return Http::response(['ok' => true], 200);
        });

        $response = app(PandaVideoClient::class)->createAiPackage('video-123');

        $this->assertSame(['ok' => true], $response);
    }

    public function test_panda_client_creates_tutor_with_default_lilia_message(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.tutor_create_path' => '/assist-ai/buy_and_create',
            'services.panda.tutor_message' => 'Converse com a tutora LilIA',
            'services.panda.ai_from_lang' => 'pt-BR',
        ]);

        Http::fake(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertSame('https://panda.test/assist-ai/buy_and_create', $request->url());
            $this->assertSame([
                'video_ids' => ['video-123'],
                'lang' => 'pt-BR',
                'name' => 'Converse com a tutora LilIA',
                'open_new_tab' => false,
                'initial_question' => 'Converse com a tutora LilIA',
                'question_suggestions' => true,
            ], $request->data());

            return Http::response(['assistant' => ['id' => 'assistant-123']], 200);
        });

        $response = app(PandaVideoClient::class)->createTutor('video-123');

        $this->assertSame(['assistant' => ['id' => 'assistant-123']], $response);
    }

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

    public function test_panda_client_finds_existing_video_when_panda_title_keeps_extension(): void
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
                    'id' => 'existing-video',
                    'title' => '01-anaytics.mp4',
                    'status' => 'CONVERTING',
                    'folder_id' => 'folder-1',
                ]],
            ], 200),
        ]);

        $video = app(PandaVideoClient::class)->findVideoByTitle('01 - Anaytics', 'folder-1');

        $this->assertSame('existing-video', $video['panda_video_id']);
    }

    public function test_panda_client_reuses_draft_video_by_title_to_avoid_duplicate_upload(): void
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
                    'id' => 'draft-video',
                    'title' => 'Aula teste.mp4',
                    'status' => 'DRAFT',
                    'folder_id' => 'folder-1',
                ]],
            ], 200),
        ]);

        $video = app(PandaVideoClient::class)->findVideoByTitle('Aula teste', 'folder-1');

        $this->assertSame('draft-video', $video['panda_video_id']);
        $this->assertSame('DRAFT', $video['panda_status']);
    }

    public function test_panda_client_does_not_create_folder_when_existing_name_is_found(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.folders_path' => '/folders',
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET' && $request->url() === 'https://panda.test/folders') {
                return Http::response([
                    'data' => [[
                        'id' => 'existing-folder',
                        'name' => 'Informática',
                        'status' => true,
                    ]],
                ]);
            }

            if ($request->method() === 'POST' && $request->url() === 'https://panda.test/folders') {
                $this->fail('Não deveria criar pasta quando já existe uma com o mesmo nome.');
            }

            return Http::response([], 404);
        });

        $folder = app(PandaVideoClient::class)->findOrCreateFolder('Informatica');

        $this->assertSame('existing-folder', $folder['panda_folder_id']);
        $this->assertFalse($folder['was_created']);
    }

    public function test_panda_client_finds_folder_when_api_returns_folders_key_and_folder_name(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.folders_path' => '/folders',
        ]);

        Http::fake([
            'https://panda.test/folders' => Http::response([
                'folders' => [[
                    'folder_id' => 'folder-caixa',
                    'folder_name' => 'Caixa',
                    'status' => true,
                ]],
            ], 200),
        ]);

        $folder = app(PandaVideoClient::class)->findFolderByName('Caixa');

        $this->assertSame('folder-caixa', $folder['panda_folder_id']);
    }

    public function test_panda_client_finds_folder_on_paginated_response(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.folders_path' => '/folders',
        ]);

        Http::fake([
            'https://panda.test/folders' => Http::response([
                'data' => [[
                    'id' => 'folder-word',
                    'name' => 'Word 365',
                    'status' => true,
                ]],
                'current_page' => 1,
                'last_page' => 2,
            ], 200),
            'https://panda.test/folders?page=2' => Http::response([
                'data' => [[
                    'id' => 'folder-excel',
                    'name' => 'Excel 365',
                    'status' => true,
                ]],
                'current_page' => 2,
                'last_page' => 2,
            ], 200),
        ]);

        $folder = app(PandaVideoClient::class)->findFolderByName('Excel 365');

        $this->assertSame('folder-excel', $folder['panda_folder_id']);
    }

    public function test_panda_client_resolves_folder_id_from_full_dashboard_url(): void
    {
        $folderId = 'b96cfc31-771f-4237-b5ca-015edb0e5a3b';

        $resolved = app(PandaVideoClient::class)->resolveFolderReference(
            'https://dashboard.pandavideo.com.br/#/folders/'.$folderId,
        );

        $this->assertSame($folderId, $resolved);
    }

    public function test_panda_client_resolves_folder_by_flexible_name_match(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.auth_header' => 'Authorization',
            'services.panda.auth_scheme' => '',
            'services.panda.folders_path' => '/folders',
        ]);

        Http::fake([
            'https://panda.test/folders' => Http::response([
                'data' => [[
                    'id' => 'folder-assistencia-social',
                    'name' => '01 - Assistência Social',
                    'status' => true,
                ]],
            ], 200),
        ]);

        $resolved = app(PandaVideoClient::class)->resolveFolderReference('Assistencia Social');

        $this->assertSame('folder-assistencia-social', $resolved);
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

    public function test_panda_client_returns_draft_after_tus_upload_to_avoid_blocking_import(): void
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
            $video = app(PandaVideoClient::class)->uploadVideo($path, 'Aula TUS', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertSame($createdVideoId, $video['panda_video_id']);
        $this->assertSame('DRAFT', $video['panda_status']);
    }

    public function test_panda_client_returns_pending_lookup_when_tus_upload_is_not_visible_yet(): void
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

        $path = sys_get_temp_dir().'/panda-client-tus-pending-lookup-test.mp4';
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

            return Http::response([], 404);
        });

        try {
            $video = app(PandaVideoClient::class)->uploadVideo($path, 'Aula TUS', 'folder-1');
        } finally {
            @unlink($path);
        }

        $this->assertSame($createdVideoId, $video['panda_video_id']);
        $this->assertSame('UPLOADED', $video['panda_status']);
        $this->assertTrue($video['panda_pending_lookup']);
        $this->assertSame('O upload TUS foi concluído, mas o vídeo ainda não apareceu na API do Panda.', $video['panda_processing_message']);
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

    public function test_panda_folder_import_accepts_duration_in_minutes_and_seconds_format(): void
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
                        'id' => 'panda-video-duration',
                        'title' => 'Lei Orgânica - Santos',
                        'duration' => '19:34',
                        'video_player' => 'https://player.test/embed/panda-video-duration',
                        'status' => 'ready',
                        'folder' => ['id' => 'folder-duration', 'name' => 'Legislação'],
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();

        app(PandaCourseImporter::class)->importFolder($course, 'folder-duration', lessonStatus: 'published');

        $module = $course->modules()->where('panda_folder_id', 'folder-duration')->firstOrFail();
        $lesson = Lesson::query()->where('panda_video_id', 'panda-video-duration')->firstOrFail();

        $this->assertSame(1174, $lesson->duration_seconds);
        $this->assertSame([
            [
                'name' => '01 - Lei Orgânica - Santos',
                'minutes' => 20,
            ],
        ], $module->planning_lessons);
    }

    public function test_sync_panda_durations_command_updates_existing_imported_lessons_and_module_planning(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos/panda-video-duration' => Http::response([
                'id' => 'panda-video-duration',
                'title' => 'Lei Orgânica - Santos',
                'duration' => '19:34',
                'status' => 'ready',
            ]),
        ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Legislação',
            'lessons' => [
                ['name' => '01 - Lei Orgânica - Santos', 'minutes' => 1],
            ],
            'workload_minutes' => 1,
        ]);
        $lesson = Lesson::factory()->create([
            'title' => '01 - Lei Orgânica - Santos',
            'duration_seconds' => 60,
            'panda_video_id' => 'panda-video-duration',
            'status' => 'published',
        ]);
        $module->onlineLessons()->sync([
            $lesson->id => ['sort_order' => 1],
        ]);

        $this->artisan('lessons:sync-panda-durations', [
            '--only-wrong' => true,
        ])->assertExitCode(0);

        $this->assertSame(1174, $lesson->fresh()->duration_seconds);
        $this->assertSame(20, $module->fresh()->workload_minutes);
        $this->assertSame([
            [
                'name' => '01 - Lei Orgânica - Santos',
                'minutes' => 20,
            ],
        ], $module->fresh()->lessons);
    }

    public function test_sync_panda_durations_command_dry_run_does_not_update_lessons(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        Http::fake([
            'panda.test/videos/panda-video-duration' => Http::response([
                'id' => 'panda-video-duration',
                'title' => 'Lei Orgânica - Santos',
                'duration' => '19:34',
                'status' => 'ready',
            ]),
        ]);

        $lesson = Lesson::factory()->create([
            'title' => '01 - Lei Orgânica - Santos',
            'duration_seconds' => 60,
            'panda_video_id' => 'panda-video-duration',
            'status' => 'published',
        ]);

        $this->artisan('lessons:sync-panda-durations', [
            '--lesson-id' => [$lesson->id],
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(60, $lesson->fresh()->duration_seconds);
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

    public function test_panda_import_publishes_ready_media_even_when_initial_status_is_draft(): void
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
                        'id' => 'ready-video',
                        'title' => 'Aula Pronta',
                        'duration_seconds' => 600,
                        'embed_url' => 'https://player.test/ready-video',
                        'status' => 'CONVERTED',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();

        app(PandaCourseImporter::class)->importFolder($course, 'folder-ready', 'Módulo Pronto', 'draft');

        $lesson = Lesson::query()->where('panda_video_id', 'ready-video')->firstOrFail();

        $this->assertSame('published', $lesson->status);
        $this->assertSame('media_ready', $lesson->source_status);
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
            'is_active' => true,
        ]);
        $course->modules()->attach($module->id, ['sort_order' => (int) $module->sort_order]);

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

    public function test_panda_import_replaces_existing_lesson_without_media_by_normalized_name(): void
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
                        'id' => 'panda-classe-palavras',
                        'title' => '02 - Classe de Palavras.mp4',
                        'duration_seconds' => 1500,
                        'status' => 'CONVERTED',
                        'embed_url' => 'https://player.test/panda-classe-palavras',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
        ]);
        $existingLesson = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => '01 - Classe de Palavras',
            'slug' => 'aula-01-classe-de-palavras',
            'description' => 'Aula placeholder.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $run = app(PandaCourseImporter::class)->importIntoModule($module, 'folder-portugues', 'published');

        $existingLesson->refresh();

        $this->assertSame('finished', $run->status);
        $this->assertSame(0, $run->summary['created']);
        $this->assertSame(1, $run->summary['updated']);
        $this->assertSame(1, Lesson::query()->count());
        $this->assertSame('01 - Classe de Palavras', $existingLesson->title);
        $this->assertSame('panda-classe-palavras', $existingLesson->panda_video_id);
        $this->assertSame('media_ready', $existingLesson->source_status);
        $this->assertTrue($module->fresh()->onlineLessons()->whereKey($existingLesson->id)->exists());
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

    public function test_panda_import_can_create_standalone_lessons_from_lessons_area(): void
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
                        'id' => 'standalone-video-1',
                        'title' => '01___aula_avulsa_do_panda (720p)',
                        'duration_seconds' => 1200,
                        'status' => 'CONVERTED',
                        'embed_url' => 'https://player.test/standalone-video-1',
                        'folder_id' => 'panda-folder-standalone',
                    ],
                ],
            ]),
        ]);

        $run = app(PandaCourseImporter::class)->importLessons(
            null,
            null,
            null,
            'panda-folder-standalone',
            'published',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'standalone-video-1')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame(1, $run->summary['videos']);
        $this->assertNull($lesson->course_id);
        $this->assertNull($lesson->course_module_id);
        $this->assertNull($lesson->course_module_track_id);
        $this->assertSame('01 - Aula Avulsa do Panda', $lesson->title);
        $this->assertSame('published', $lesson->status);
        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame('panda-folder-standalone', $lesson->metadata['folder_id']);
    }

    public function test_panda_import_from_lessons_area_accepts_full_folder_url(): void
    {
        config([
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.videos_path' => '/videos',
        ]);

        $folderId = 'b96cfc31-771f-4237-b5ca-015edb0e5a3b';
        $folderUrl = 'https://dashboard.pandavideo.com.br/#/folders/'.$folderId;

        Http::fake([
            'panda.test/videos?folder_id='.$folderId => Http::response([
                'data' => [
                    [
                        'id' => 'standalone-url-video-1',
                        'title' => '01 - Aula pela URL',
                        'duration_seconds' => 900,
                        'status' => 'CONVERTED',
                        'embed_url' => 'https://player.test/standalone-url-video-1',
                        'folder_id' => $folderId,
                    ],
                ],
            ]),
        ]);

        $run = app(PandaCourseImporter::class)->importLessons(
            null,
            null,
            null,
            $folderUrl,
            'published',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'standalone-url-video-1')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame($folderId, $run->panda_folder_id);
        $this->assertSame($folderId, $lesson->metadata['folder_id']);
        $this->assertSame($folderUrl, $lesson->metadata['folder_reference']);
    }

    public function test_panda_import_from_lessons_area_links_ready_lesson_to_course_by_approximate_planning_name(): void
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
                        'id' => 'standalone-classes-palavras',
                        'title' => 'Aula 03 - Classes de Palavras Conjuncao Subordinativa Adverbial.mp4',
                        'duration_seconds' => 1180,
                        'status' => 'CONVERTED',
                        'embed_url' => 'https://player.test/standalone-classes-palavras',
                        'folder_id' => 'panda-folder-standalone',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create(['name' => 'Gabaritando Santos']);
        $module = CourseModule::factory()->for($course)->create([
            'name' => 'Português',
            'is_active' => true,
            'lessons' => [
                ['name' => 'Classes de Palavras - Conjunção Subordinativa Adverbial', 'minutes' => 45],
                ['name' => 'Pontuação - Parte 01', 'minutes' => 45],
            ],
        ]);

        $run = app(PandaCourseImporter::class)->importLessons(
            $course,
            null,
            null,
            'panda-folder-standalone',
            'draft',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'standalone-classes-palavras')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertSame('published', $lesson->fresh()->status);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $lesson->id,
            'sort_order' => 1,
        ]);
    }

    public function test_panda_import_from_lessons_area_can_link_lessons_to_module_and_track(): void
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
                        'id' => 'linked-video-1',
                        'title' => '01 - Windows 10',
                        'duration_seconds' => 900,
                        'status' => 'CONVERTED',
                        'embed_url' => 'https://player.test/linked-video-1',
                        'folder_id' => 'panda-folder-windows',
                    ],
                ],
            ]),
        ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Informática',
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $run = app(PandaCourseImporter::class)->importLessons(
            $course,
            $module,
            $track,
            'panda-folder-windows',
            'draft',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'linked-video-1')->firstOrFail();

        $this->assertSame('finished', $run->status);
        $this->assertTrue($module->fresh()->courses()->whereKey($course->id)->exists());
        $this->assertTrue($track->fresh()->courses()->whereKey($course->id)->exists());
        $this->assertTrue($module->fresh()->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertTrue($track->fresh()->lessons()->whereKey($lesson->id)->exists());
        $this->assertSame('panda-folder-windows', $module->fresh()->panda_folder_id);
        $this->assertSame('panda-folder-windows', $track->fresh()->panda_folder_id);
    }
}
