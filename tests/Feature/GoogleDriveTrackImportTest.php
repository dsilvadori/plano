<?php

namespace Tests\Feature;

use App\Jobs\ImportGoogleDriveLessons;
use App\Jobs\ImportGoogleDriveModuleTracks;
use App\Jobs\ReprocessGoogleDrivePendingLessons;
use App\Jobs\SyncPandaVideoStatus;
use App\Jobs\UploadLessonToPanda;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\GoogleDriveImportRun;
use App\Models\Lesson;
use App\Services\GoogleDriveClient;
use App\Services\GoogleDriveTrackImporter;
use App\Services\PandaVideoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class GoogleDriveTrackImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_each_subfolder_as_track_and_files_as_lessons(): void
    {
        Cache::forget('google-drive:service-account-token');

        $credentialsPath = tempnam(sys_get_temp_dir(), 'google-drive-credentials-');
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $privateKeyText);
        file_put_contents($credentialsPath, json_encode([
            'type' => 'service_account',
            'client_email' => 'drive-admin@example.iam.gserviceaccount.com',
            'private_key' => $privateKeyText,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_drive.enabled' => true,
            'services.google_drive.credentials_path' => $credentialsPath,
            'services.google_drive.api_base_url' => 'https://www.googleapis.com/drive/v3',
            'services.google_drive.scopes' => 'https://www.googleapis.com/auth/drive.readonly',
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'google-token'], 200);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $driveQuery = (string) ($query['q'] ?? '');

            if (str_contains($driveQuery, "'root-folder' in parents")) {
                return Http::response([
                    'files' => [
                        [
                            'id' => 'folder-windows-10',
                            'name' => 'Windows 10',
                            'mimeType' => 'application/vnd.google-apps.folder',
                            'webViewLink' => 'https://drive.test/folders/windows-10',
                        ],
                        [
                            'id' => 'folder-excel',
                            'name' => 'Excel 2016',
                            'mimeType' => 'application/vnd.google-apps.folder',
                            'webViewLink' => 'https://drive.test/folders/excel',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($driveQuery, "'folder-windows-10' in parents")) {
                return Http::response([
                    'files' => [
                        [
                            'id' => 'doc-windows-01',
                            'name' => '01 - Windows 10',
                            'mimeType' => 'application/vnd.google-apps.document',
                            'webViewLink' => 'https://drive.test/docs/windows-01',
                        ],
                        [
                            'id' => 'pdf-windows-02',
                            'name' => '02 - Explorador de Arquivos.pdf',
                            'mimeType' => 'application/pdf',
                            'webViewLink' => 'https://drive.test/docs/windows-02',
                        ],
                    ],
                ], 200);
            }

            if (str_contains($driveQuery, "'folder-excel' in parents")) {
                return Http::response([
                    'files' => [
                        [
                            'id' => 'doc-excel-01',
                            'name' => '01 - Conceitos Gerais',
                            'mimeType' => 'application/vnd.google-apps.document',
                            'webViewLink' => 'https://drive.test/docs/excel-01',
                        ],
                    ],
                ], 200);
            }

            return Http::response(['files' => []], 200);
        });

        try {
            $course = Course::factory()->create();
            $module = CourseModule::factory()->create([
                'course_id' => $course->id,
                'name' => 'Informática',
                'is_active' => true,
            ]);

            $summary = app(GoogleDriveTrackImporter::class)->importFolderSubfoldersAsTracks(
                $course,
                $module,
                'https://drive.google.com/drive/folders/root-folder',
                createPandaFolders: false,
            );
        } finally {
            @unlink($credentialsPath);
        }

        $this->assertSame(2, $summary['tracks']);
        $this->assertSame(3, $summary['created_lessons']);
        $this->assertDatabaseHas('course_module_tracks', [
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'google_doc_url' => 'https://drive.test/folders/windows-10',
        ]);
        $this->assertDatabaseHas('course_module_tracks', [
            'course_module_id' => $module->id,
            'name' => 'Excel 2016',
            'slug' => 'excel-2016',
        ]);

        $windowsTrack = CourseModuleTrack::query()->where('slug', 'windows-10')->firstOrFail();

        $this->assertTrue($windowsTrack->courses()->whereKey($course->id)->exists());
        $this->assertSame(1, $windowsTrack->lessons()->count());
        $this->assertDatabaseHas('lessons', [
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01 - Windows 10',
            'type' => 'text',
            'status' => 'draft',
            'source_status' => 'awaiting_media',
            'google_doc_url' => 'https://drive.test/docs/windows-01',
        ]);
        $windowsLesson = Lesson::query()->where('title', '01 - Windows 10')->firstOrFail();
        $this->assertFalse($module->onlineLessons()->whereKey($windowsLesson->id)->exists());
        $this->assertFalse($windowsTrack->lessons()->whereKey($windowsLesson->id)->exists());
        $this->assertDatabaseHas('lessons', [
            'title' => '02 - Explorador de Arquivos',
            'type' => 'pdf',
            'source_status' => 'media_ready',
        ]);
        $pdfLesson = Lesson::query()->where('title', '02 - Explorador de Arquivos')->firstOrFail();
        $this->assertTrue($module->onlineLessons()->whereKey($pdfLesson->id)->exists());
        $this->assertTrue($windowsTrack->lessons()->whereKey($pdfLesson->id)->exists());
        $this->assertSame(3, Lesson::query()->count());
    }

    public function test_import_can_create_panda_module_and_track_folders(): void
    {
        Cache::forget('google-drive:service-account-token');

        $credentialsPath = tempnam(sys_get_temp_dir(), 'google-drive-credentials-');
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($privateKey, $privateKeyText);
        file_put_contents($credentialsPath, json_encode([
            'type' => 'service_account',
            'client_email' => 'drive-admin@example.iam.gserviceaccount.com',
            'private_key' => $privateKeyText,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.google_drive.enabled' => true,
            'services.google_drive.credentials_path' => $credentialsPath,
            'services.google_drive.api_base_url' => 'https://www.googleapis.com/drive/v3',
            'services.google_drive.scopes' => 'https://www.googleapis.com/auth/drive.readonly',
            'services.panda.api_key' => 'test-key',
            'services.panda.base_url' => 'https://panda.test',
            'services.panda.folders_path' => '/folders',
            'services.panda.folder_parent_payload_key' => '',
            'services.panda.folder_parent_query_param' => '',
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'google-token'], 200);
            }

            if (str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $driveQuery = (string) ($query['q'] ?? '');

                if (str_contains($driveQuery, "'root-folder' in parents")) {
                    return Http::response([
                        'files' => [[
                            'id' => 'folder-windows-10',
                            'name' => 'Windows 10',
                            'mimeType' => 'application/vnd.google-apps.folder',
                            'webViewLink' => 'https://drive.test/folders/windows-10',
                        ]],
                    ], 200);
                }

                if (str_contains($driveQuery, "'folder-windows-10' in parents")) {
                    return Http::response(['files' => []], 200);
                }
            }

            if (str_starts_with($request->url(), 'https://panda.test/folders')) {
                if ($request->method() === 'GET') {
                    $this->assertStringNotContainsString('parent_id', $request->url());

                    return Http::response(['data' => []], 200);
                }

                $payload = $request->data();
                $this->assertArrayNotHasKey('parent_id', $payload);

                return Http::response([
                    'id' => $payload['name'] === 'Windows 10' ? 'panda-track-windows-10' : 'panda-module-informatica',
                    'name' => $payload['name'],
                ], 201);
            }

            return Http::response([], 404);
        });

        try {
            $course = Course::factory()->create();
            $module = CourseModule::factory()->create([
                'course_id' => $course->id,
                'name' => 'Informática',
            ]);

            $summary = app(GoogleDriveTrackImporter::class)->importFolderSubfoldersAsTracks(
                $course,
                $module,
                'https://drive.google.com/drive/folders/root-folder',
            );
        } finally {
            @unlink($credentialsPath);
        }

        $track = CourseModuleTrack::query()->where('slug', 'windows-10')->firstOrFail();

        $this->assertSame(2, $summary['panda_folders']);
        $this->assertSame('panda-module-informatica', $module->fresh()->panda_folder_id);
        $this->assertSame('panda-track-windows-10', $track->panda_folder_id);
        $this->assertSame('panda-module-informatica', $track->metadata['panda_parent_folder_id']);
    }

    public function test_import_uploads_drive_videos_to_panda_and_links_lessons(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')
            ->once()
            ->with('root-folder')
            ->andReturn('root-folder');
        $drive->shouldReceive('listFolders')
            ->once()
            ->with('root-folder')
            ->andReturn([[
                'id' => 'folder-windows-10',
                'name' => 'Windows 10',
                'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
                'webViewLink' => 'https://drive.test/folders/windows-10',
            ]]);
        $drive->shouldReceive('listFiles')
            ->once()
            ->with('folder-windows-10')
            ->andReturn([[
                'id' => 'video-windows-01',
                'name' => '01 - Introdução ao Windows.mp4',
                'mimeType' => 'video/mp4',
                'webViewLink' => 'https://drive.test/file/video-windows-01',
            ]]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-windows-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Informática', null)
            ->andReturn([
                'panda_folder_id' => 'panda-module-informatica',
                'name' => 'Informática',
                'payload' => [],
            ]);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'panda-module-informatica')
            ->andReturn([
                'panda_folder_id' => 'panda-track-windows-10',
                'name' => 'Windows 10',
                'payload' => [],
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn([
                'panda_video_id' => 'panda-video-windows-01',
                'title' => '01 - Introdução ao Windows',
                'description' => null,
                'duration_seconds' => 1590,
                'thumbnail_url' => 'https://cdn.test/thumb.jpg',
                'panda_status' => 'processing',
                'panda_embed_url' => 'https://player.test/embed/panda-video-windows-01',
                'panda_player_url' => 'https://player.test/panda-video-windows-01',
                'folder_id' => 'panda-track-windows-10',
                'payload' => ['id' => 'panda-video-windows-01'],
            ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'folder_url' => 'root-folder',
            'status' => 'running',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
            run: $run,
        );

        $lesson = Lesson::query()->where('panda_video_id', 'panda-video-windows-01')->firstOrFail();

        $this->assertSame(1, $summary['panda_videos_uploaded']);
        $this->assertSame('video', $lesson->type);
        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame('processing', $lesson->panda_status);
        $this->assertSame('https://player.test/embed/panda-video-windows-01', $lesson->panda_embed_url);
        $this->assertSame(1, $run->fresh()->total_tracks);
        $this->assertSame(1, $run->fresh()->processed_tracks);
        $this->assertSame(1, $run->fresh()->total_lessons);
        $this->assertSame(1, $run->fresh()->processed_lessons);
        $this->assertSame(1, $run->fresh()->panda_videos_uploaded);
        $this->assertSame(100, $run->fresh()->progress_percent);
    }

    public function test_import_keeps_lesson_when_panda_video_upload_fails(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([[
            'id' => 'video-windows-01',
            'name' => '01 - Introdução ao Windows.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Informática', null)
            ->andReturn(['panda_folder_id' => 'panda-module-informatica', 'name' => 'Informática']);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'panda-track-windows-10', 'name' => 'Windows 10']);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->andThrow(new \RuntimeException('HTTP content length exceeded 10485760 bytes.'));

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $lesson = Lesson::query()->where('slug', '01-introducao-ao-windows')->firstOrFail();

        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_failed']);
        $this->assertSame('awaiting_media', $lesson->source_status);
        $this->assertNull($lesson->panda_video_id);
        $this->assertSame('HTTP content length exceeded 10485760 bytes.', $lesson->metadata['panda_upload_error']);
    }

    public function test_import_retries_panda_concurrency_limit_and_reuses_video_that_appears(): void
    {
        config([
            'services.panda.video_upload_retry_attempts' => 2,
            'services.panda.video_upload_retry_delay_seconds' => 0,
        ]);

        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([[
            'id' => 'video-windows-01',
            'name' => '01 - Introdução ao Windows.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Informática', null)
            ->andReturn(['panda_folder_id' => 'panda-module-informatica', 'name' => 'Informática', 'was_created' => true]);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'panda-track-windows-10', 'name' => 'Windows 10', 'was_created' => true]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andThrow(new \RuntimeException('{"errCode":10,"errMsg":"you have reached the upload concurrency limit, please wait and try again"}'));
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn([
                'panda_video_id' => 'existing-after-retry',
                'title' => '01 - Introdução ao Windows.mp4',
                'description' => null,
                'duration_seconds' => 1590,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/existing-after-retry',
                'panda_player_url' => 'https://player.test/existing-after-retry',
                'folder_id' => 'panda-track-windows-10',
                'payload' => ['id' => 'existing-after-retry'],
            ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $lesson = Lesson::query()->where('slug', '01-introducao-ao-windows')->firstOrFail();

        $this->assertSame(0, $summary['panda_videos_failed']);
        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_skipped']);
        $this->assertSame('existing-after-retry', $lesson->panda_video_id);
        $this->assertSame('media_ready', $lesson->source_status);
    }

    public function test_import_links_existing_panda_video_by_title_without_uploading_duplicate(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([[
            'id' => 'video-windows-01',
            'name' => '01 - Introdução ao Windows.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldNotReceive('downloadFileToPath');

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Informática', null)
            ->andReturn(['panda_folder_id' => 'panda-module-informatica', 'name' => 'Informática']);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'panda-track-windows-10', 'name' => 'Windows 10']);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn([
                'panda_video_id' => 'existing-panda-video-windows-01',
                'title' => '01 - Introdução ao Windows',
                'description' => null,
                'duration_seconds' => 1590,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/existing-panda-video-windows-01',
                'panda_player_url' => 'https://player.test/existing-panda-video-windows-01',
                'folder_id' => 'panda-track-windows-10',
                'payload' => ['id' => 'existing-panda-video-windows-01'],
            ]);
        $panda->shouldNotReceive('uploadVideo');

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $lesson = Lesson::query()->where('slug', '01-introducao-ao-windows')->firstOrFail();

        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_skipped']);
        $this->assertSame('existing-panda-video-windows-01', $lesson->panda_video_id);
        $this->assertSame('media_ready', $lesson->source_status);
    }

    public function test_import_reuploads_when_local_panda_video_is_not_processable(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([[
            'id' => 'video-windows-01',
            'name' => '01 - Introdução ao Windows.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'panda-module-informatica', 'name' => 'Informática', 'status' => true]);
        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('panda-track-windows-10')
            ->andReturn(['panda_folder_id' => 'panda-track-windows-10', 'name' => 'Windows 10', 'status' => true]);
        $panda->shouldReceive('reusableVideo')
            ->once()
            ->with('deleting-panda-video-windows-01', 'panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn([
                'panda_video_id' => 'new-panda-video-windows-01',
                'title' => '01 - Introdução ao Windows',
                'description' => null,
                'duration_seconds' => 0,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/new-panda-video-windows-01',
                'panda_player_url' => 'https://player.test/new-panda-video-windows-01',
                'folder_id' => 'panda-track-windows-10',
                'payload' => ['id' => 'new-panda-video-windows-01'],
            ]);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
            'panda_folder_id' => 'panda-module-informatica',
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'panda_folder_id' => 'panda-track-windows-10',
            'sort_order' => 1,
            'status' => 'draft',
        ]);
        Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Introdução ao Windows',
            'slug' => '01-introducao-ao-windows',
            'type' => 'video',
            'status' => 'draft',
            'panda_video_id' => 'deleting-panda-video-windows-01',
            'sort_order' => 1,
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $lesson = Lesson::query()->where('slug', '01-introducao-ao-windows')->firstOrFail();

        $this->assertSame(1, $summary['panda_videos_uploaded']);
        $this->assertSame(0, $summary['panda_videos_skipped']);
        $this->assertSame('new-panda-video-windows-01', $lesson->panda_video_id);
        $this->assertSame('CONVERTING', $lesson->panda_status);
    }

    public function test_import_reuses_existing_local_panda_folder_ids_without_creating_duplicates(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([]);

        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('existing-panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'existing-panda-module-informatica', 'name' => 'Informática', 'status' => true]);
        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('existing-panda-track-windows-10')
            ->andReturn(['panda_folder_id' => 'existing-panda-track-windows-10', 'name' => 'Windows 10', 'status' => true]);
        $panda->shouldNotReceive('findOrCreateFolder');

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
            'panda_folder_id' => 'existing-panda-module-informatica',
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'panda_folder_id' => 'existing-panda-track-windows-10',
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $this->assertSame(0, $summary['panda_folders']);
        $this->assertSame('existing-panda-module-informatica', $module->fresh()->panda_folder_id);
        $this->assertSame('existing-panda-track-windows-10', $track->fresh()->panda_folder_id);
    }

    public function test_import_recreates_inactive_local_panda_folders(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFolders')->once()->andReturn([[
            'id' => 'folder-windows-10',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->andReturn([]);

        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('inactive-panda-module-informatica')
            ->andReturn(null);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Informática', null)
            ->andReturn(['panda_folder_id' => 'active-panda-module-informatica', 'name' => 'Informática']);
        $panda->shouldReceive('activeFolder')
            ->once()
            ->with('inactive-panda-track-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'active-panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'active-panda-track-windows-10', 'name' => 'Windows 10']);

        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
            'panda_folder_id' => 'inactive-panda-module-informatica',
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'panda_folder_id' => 'inactive-panda-track-windows-10',
            'sort_order' => 1,
            'status' => 'draft',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderSubfoldersAsTracks(
            $course,
            $module,
            'root-folder',
        );

        $this->assertSame(2, $summary['panda_folders']);
        $this->assertSame('active-panda-module-informatica', $module->fresh()->panda_folder_id);
        $this->assertSame('active-panda-track-windows-10', $track->fresh()->panda_folder_id);
    }

    public function test_imports_drive_folder_files_as_standalone_lessons(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')
            ->once()
            ->with('standalone-folder')
            ->andReturn('standalone-folder');
        $drive->shouldReceive('listFiles')
            ->once()
            ->with('standalone-folder')
            ->andReturn([
                [
                    'id' => 'video-standalone-01',
                    'name' => '01 - Aula Avulsa.mp4',
                    'mimeType' => 'video/mp4',
                    'webViewLink' => 'https://drive.test/file/video-standalone-01',
                ],
                [
                    'id' => 'pdf-standalone-02',
                    'name' => '02 - Material.pdf',
                    'mimeType' => 'application/pdf',
                    'webViewLink' => 'https://drive.test/file/pdf-standalone-02',
                ],
            ]);
        $drive->shouldReceive('listFolders')
            ->once()
            ->with('standalone-folder')
            ->andReturn([]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-standalone-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Aulas avulsas', null)
            ->andReturn([
                'panda_folder_id' => 'panda-standalone-folder',
                'name' => 'Aulas avulsas',
                'was_created' => true,
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Aula Avulsa', 'panda-standalone-folder')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Aula Avulsa', 'panda-standalone-folder')
            ->andReturn([
                'panda_video_id' => 'panda-video-standalone-01',
                'title' => '01 - Aula Avulsa',
                'description' => null,
                'duration_seconds' => 0,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/panda-video-standalone-01',
                'panda_player_url' => 'https://player.test/panda-video-standalone-01',
                'folder_id' => 'panda-standalone-folder',
                'payload' => ['id' => 'panda-video-standalone-01'],
            ]);

        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'standalone-folder',
            'status' => 'running',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'standalone-folder',
            pandaFolderName: 'Aulas avulsas',
            run: $run,
        );

        $this->assertSame(2, $summary['created_lessons']);
        $this->assertSame(2, $summary['total_lessons']);
        $this->assertSame(1, $summary['panda_folders']);
        $this->assertSame(1, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_failed']);
        $this->assertDatabaseHas('lessons', [
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01 - Aula Avulsa',
            'type' => 'video',
            'source_status' => 'panda_processing',
            'panda_video_id' => 'panda-video-standalone-01',
        ]);
        $this->assertDatabaseHas('lessons', [
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '02 - Material',
            'type' => 'pdf',
            'source_status' => 'media_ready',
        ]);
        $this->assertSame(2, $run->fresh()->processed_lessons);
        $this->assertSame(100, $run->fresh()->progress_percent);
    }

    public function test_drive_standalone_import_links_ready_lesson_to_course_by_approximate_planning_name(): void
    {
        config(['services.panda.queue_drive_uploads' => false]);

        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')
            ->once()
            ->with('standalone-folder')
            ->andReturn('standalone-folder');
        $drive->shouldReceive('listFiles')
            ->once()
            ->with('standalone-folder')
            ->andReturn([[
                'id' => 'video-classes-palavras',
                'name' => 'Aula 03 - Classes de Palavras Conjuncao Subordinativa Adverbial.mp4',
                'mimeType' => 'video/mp4',
                'webViewLink' => 'https://drive.test/file/video-classes-palavras',
            ]]);
        $drive->shouldReceive('listFolders')
            ->once()
            ->with('standalone-folder')
            ->andReturn([]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-classes-palavras', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Aulas avulsas', null)
            ->andReturn([
                'panda_folder_id' => 'panda-standalone-folder',
                'name' => 'Aulas avulsas',
                'was_created' => true,
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('03 - Classes de Palavras Conjunção Subordinativa Adverbial', 'panda-standalone-folder')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '03 - Classes de Palavras Conjunção Subordinativa Adverbial', 'panda-standalone-folder')
            ->andReturn([
                'panda_video_id' => 'panda-video-classes-palavras',
                'title' => '03 - Classes de Palavras Conjunção Subordinativa Adverbial',
                'description' => null,
                'duration_seconds' => 1180,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/panda-video-classes-palavras',
                'panda_player_url' => 'https://player.test/panda-video-classes-palavras',
                'folder_id' => 'panda-standalone-folder',
                'payload' => ['id' => 'panda-video-classes-palavras'],
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

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            $course,
            null,
            null,
            'standalone-folder',
            pandaFolderName: 'Aulas avulsas',
        );

        $lesson = Lesson::query()->where('panda_video_id', 'panda-video-classes-palavras')->firstOrFail();

        $this->assertSame(1, $summary['created_lessons']);
        $this->assertSame('published', $lesson->fresh()->status);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $lesson->id,
            'sort_order' => 1,
        ]);
    }

    public function test_imports_standalone_lessons_from_drive_subfolders_without_creating_tracks(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')
            ->once()
            ->with('root-folder')
            ->andReturn('root-folder');
        $drive->shouldReceive('listFiles')
            ->once()
            ->with('root-folder')
            ->andReturn([]);
        $drive->shouldReceive('listFolders')
            ->once()
            ->with('root-folder')
            ->andReturn([[
                'id' => 'subfolder-windows',
                'name' => 'Windows 10',
                'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
            ]]);
        $drive->shouldReceive('listFiles')
            ->once()
            ->with('subfolder-windows')
            ->andReturn([[
                'id' => 'video-windows-01',
                'name' => '01 - Introdução ao Windows.mp4',
                'mimeType' => 'video/mp4',
                'webViewLink' => 'https://drive.test/file/video-windows-01',
            ]]);
        $drive->shouldReceive('listFolders')
            ->once()
            ->with('subfolder-windows')
            ->andReturn([]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-windows-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Windows 10')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-windows-10',
                'name' => 'Windows 10',
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Windows', 'panda-folder-windows-10')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Windows', 'panda-folder-windows-10')
            ->andReturn([
                'panda_video_id' => 'panda-video-windows-01',
                'title' => '01 - Introdução ao Windows',
                'description' => null,
                'duration_seconds' => 1590,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/panda-video-windows-01',
                'panda_player_url' => 'https://player.test/panda-video-windows-01',
                'folder_id' => 'panda-folder-windows-10',
                'payload' => ['id' => 'panda-video-windows-01'],
            ]);

        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'root-folder',
            'status' => 'running',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            createPandaFolder: false,
            run: $run,
        );

        $lesson = Lesson::query()->where('title', '01 - Introdução ao Windows')->firstOrFail();

        $this->assertSame(1, $summary['created_lessons']);
        $this->assertSame(0, $summary['created_tracks']);
        $this->assertSame(1, $summary['total_lessons']);
        $this->assertSame(0, CourseModuleTrack::query()->count());
        $this->assertNull($lesson->course_module_id);
        $this->assertNull($lesson->course_module_track_id);
        $this->assertSame('Windows 10', $lesson->metadata['drive_source_folder_path']);
        $this->assertSame('panda-folder-windows-10', $lesson->metadata['panda_folder_id']);
        $this->assertSame(1590, $lesson->duration_seconds);
        $this->assertSame(1, $run->fresh()->total_lessons);
        $this->assertSame(1, $run->fresh()->processed_lessons);
    }

    public function test_drive_import_continues_when_one_subfolder_cannot_be_read(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->with('root-folder')->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([
            [
                'id' => 'folder-ok',
                'name' => 'Excel 2016',
                'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
            ],
            [
                'id' => 'folder-error',
                'name' => 'Pasta com erro',
                'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
            ],
        ]);
        $drive->shouldReceive('listFiles')->once()->with('folder-ok')->andReturn([[
            'id' => 'pdf-excel-01',
            'name' => '01 - Material.pdf',
            'mimeType' => 'application/pdf',
            'webViewLink' => 'https://drive.test/pdf-excel-01',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('folder-ok')->andReturn([]);
        $drive->shouldReceive('listFiles')->once()->with('folder-error')->andThrow(new \RuntimeException('Permissão negada'));
        $drive->shouldReceive('listFolders')->once()->with('folder-error')->andReturn([]);
        $panda->shouldNotReceive('uploadVideo');

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            uploadPandaVideos: false,
        );

        $this->assertSame(1, $summary['created_lessons']);
        $this->assertSame(1, $summary['total_lessons']);
        $this->assertNotEmpty($summary['warnings']);
        $this->assertStringContainsString('Pasta com erro', $summary['warnings'][0]);
        $this->assertDatabaseHas('lessons', [
            'title' => '01 - Material',
            'type' => 'pdf',
            'source_status' => 'media_ready',
        ]);
    }

    public function test_standalone_drive_import_blocks_upload_when_matching_panda_folder_is_missing(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'subfolder-windows',
            'name' => 'Windows 10',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('subfolder-windows')->andReturn([[
            'id' => 'video-windows-01',
            'name' => '01 - Introdução ao Windows.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('subfolder-windows')->andReturn([]);
        $drive->shouldNotReceive('downloadFileToPath');

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Windows 10')
            ->andReturn(null);
        $panda->shouldNotReceive('findVideoByTitle');
        $panda->shouldNotReceive('uploadVideo');

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            createPandaFolder: false,
        );

        $lesson = Lesson::query()->where('title', '01 - Introdução ao Windows')->firstOrFail();

        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(0, $summary['panda_videos_skipped']);
        $this->assertSame(1, $summary['panda_videos_failed']);
        $this->assertNull($lesson->panda_video_id);
        $this->assertNull($lesson->metadata['panda_folder_id']);
        $this->assertStringContainsString('Pasta Panda não encontrada', $lesson->metadata['panda_upload_error']);
    }

    public function test_standalone_drive_import_creates_missing_panda_folder_from_subfolder_name(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'subfolder-word',
            'name' => 'Word 365',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('subfolder-word')->andReturn([[
            'id' => 'video-word-01',
            'name' => '01 - Introdução ao Word.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('subfolder-word')->andReturn([]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-word-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Word 365')
            ->andReturn(null);
        $panda->shouldReceive('createFolder')
            ->once()
            ->with('Word 365')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-word-365',
                'name' => 'Word 365',
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução ao Word', 'panda-folder-word-365')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Word', 'panda-folder-word-365')
            ->andReturn([
                'panda_video_id' => 'panda-video-word-01',
                'title' => '01 - Introdução ao Word',
                'duration_seconds' => 1320,
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/panda-video-word-01',
                'panda_player_url' => 'https://player.test/panda-video-word-01',
                'folder_id' => 'panda-folder-word-365',
                'payload' => ['id' => 'panda-video-word-01'],
            ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
        );

        $lesson = Lesson::query()->where('title', '01 - Introdução ao Word')->firstOrFail();

        $this->assertSame(1, $summary['panda_folders']);
        $this->assertSame(1, $summary['panda_videos_uploaded']);
        $this->assertSame('panda-folder-word-365', $lesson->metadata['panda_folder_id']);
        $this->assertSame(1320, $lesson->duration_seconds);
        $this->assertNull($lesson->metadata['panda_upload_error']);
    }

    public function test_standalone_drive_import_matches_nested_folder_without_numeric_prefix(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'folder-office',
            'name' => 'OFFICE 2016 - NOVAS',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('folder-office')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('folder-office')->andReturn([[
            'id' => 'folder-excel',
            'name' => '02 - Excel 2016',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('folder-excel')->andReturn([[
            'id' => 'video-excel-01',
            'name' => '01 - Introdução.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('folder-excel')->andReturn([]);
        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('video-excel-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('02 - Excel 2016')
            ->andReturn(null);
        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Excel 2016')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-excel-2016',
                'name' => 'Excel 2016',
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução', 'panda-folder-excel-2016')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução', 'panda-folder-excel-2016')
            ->andReturn([
                'panda_video_id' => 'panda-video-excel-01',
                'title' => '01 - Introdução',
                'panda_status' => 'CONVERTING',
                'panda_embed_url' => 'https://player.test/embed/panda-video-excel-01',
                'panda_player_url' => 'https://player.test/panda-video-excel-01',
                'folder_id' => 'panda-folder-excel-2016',
                'payload' => ['id' => 'panda-video-excel-01'],
            ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            createPandaFolder: false,
        );

        $lesson = Lesson::query()->where('title', '01 - Introdução')->firstOrFail();

        $this->assertSame(1, $summary['panda_videos_uploaded']);
        $this->assertSame('panda-folder-excel-2016', $lesson->metadata['panda_folder_id']);
        $this->assertSame('OFFICE 2016 - NOVAS/02 - Excel 2016', $lesson->metadata['drive_source_folder_path']);
    }

    public function test_standalone_drive_import_reuses_existing_platform_lesson_by_name(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $existingLesson = Lesson::query()->create([
            'title' => '01 - Introdução',
            'slug' => 'aula-01-introducao',
            'description' => 'Aula existente.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'media_ready',
            'panda_video_id' => 'existing-platform-video',
            'panda_embed_url' => 'https://player.test/embed/existing-platform-video',
            'panda_status' => 'CONVERTED',
        ]);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'folder-excel',
            'name' => 'Excel 2016',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('folder-excel')->andReturn([[
            'id' => 'video-excel-01',
            'name' => '01 - Introdução.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('folder-excel')->andReturn([]);
        $drive->shouldNotReceive('downloadFileToPath');

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Excel 2016')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-excel-2016',
                'name' => 'Excel 2016',
            ]);
        $panda->shouldReceive('reusableVideo')
            ->once()
            ->with('existing-platform-video', 'panda-folder-excel-2016')
            ->andReturn([
                'panda_video_id' => 'existing-platform-video',
                'title' => '01 - Introdução',
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/existing-platform-video',
                'panda_player_url' => 'https://player.test/existing-platform-video',
                'folder_id' => 'panda-folder-excel-2016',
                'payload' => ['id' => 'existing-platform-video'],
            ]);
        $panda->shouldNotReceive('uploadVideo');

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            createPandaFolder: false,
        );

        $existingLesson->refresh();

        $this->assertSame(1, Lesson::query()->where('title', '01 - Introdução')->count());
        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_skipped']);
        $this->assertSame('media_ready', $existingLesson->source_status);
        $this->assertSame('existing-platform-video', $existingLesson->panda_video_id);
        $this->assertNull($existingLesson->metadata['panda_upload_error']);
        $this->assertSame('panda-folder-excel-2016', $existingLesson->metadata['panda_folder_id']);
    }

    public function test_drive_import_replaces_existing_lesson_without_media_by_normalized_name(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $existingLesson = Lesson::query()->create([
            'title' => '01 - Classe de Palavras',
            'slug' => 'aula-01-classe-de-palavras',
            'description' => 'Aula placeholder.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'folder-portugues',
            'name' => 'Português',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('folder-portugues')->andReturn([[
            'id' => 'video-classe-palavras',
            'name' => '02 - Classe de Palavras.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('folder-portugues')->andReturn([]);
        $drive->shouldNotReceive('downloadFileToPath');

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Português')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-portugues',
                'name' => 'Português',
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('02 - Classe de Palavras', 'panda-folder-portugues')
            ->andReturn([
                'panda_video_id' => 'panda-video-classe-palavras',
                'title' => '02 - Classe de Palavras',
                'duration_seconds' => 1500,
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/panda-video-classe-palavras',
                'panda_player_url' => 'https://player.test/panda-video-classe-palavras',
                'folder_id' => 'panda-folder-portugues',
                'payload' => ['id' => 'panda-video-classe-palavras'],
            ]);
        $panda->shouldNotReceive('uploadVideo');

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            createPandaFolder: false,
        );

        $existingLesson->refresh();

        $this->assertSame(0, $summary['created_lessons']);
        $this->assertSame(1, $summary['updated_lessons']);
        $this->assertSame(1, Lesson::query()->count());
        $this->assertSame('02 - Classe de Palavras', $existingLesson->title);
        $this->assertSame('panda-video-classe-palavras', $existingLesson->panda_video_id);
        $this->assertSame('media_ready', $existingLesson->source_status);
    }

    public function test_standalone_drive_import_queues_missing_panda_upload_when_enabled(): void
    {
        config(['services.panda.queue_drive_uploads' => true]);
        Queue::fake();

        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldReceive('folderIdFromUrl')->once()->andReturn('root-folder');
        $drive->shouldReceive('listFiles')->once()->with('root-folder')->andReturn([]);
        $drive->shouldReceive('listFolders')->once()->with('root-folder')->andReturn([[
            'id' => 'folder-excel',
            'name' => 'Excel 2016',
            'mimeType' => GoogleDriveClient::FOLDER_MIME_TYPE,
        ]]);
        $drive->shouldReceive('listFiles')->once()->with('folder-excel')->andReturn([[
            'id' => 'video-excel-01',
            'name' => '01 - Introdução.mp4',
            'mimeType' => 'video/mp4',
        ]]);
        $drive->shouldReceive('listFolders')->once()->with('folder-excel')->andReturn([]);
        $drive->shouldNotReceive('downloadFileToPath');

        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Excel 2016')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-excel-2016',
                'name' => 'Excel 2016',
            ]);
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução', 'panda-folder-excel-2016')
            ->andReturn(null);
        $panda->shouldNotReceive('uploadVideo');

        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'root-folder',
            'folder_id' => 'root-folder',
            'status' => 'running',
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->importFolderFilesAsLessons(
            null,
            null,
            null,
            'root-folder',
            run: $run,
        );

        $lesson = Lesson::query()->where('title', '01 - Introdução')->firstOrFail();

        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_failed']);
        $this->assertSame('upload_queued', $lesson->source_status);
        $this->assertSame('panda-folder-excel-2016', $lesson->metadata['panda_folder_id']);
        $this->assertNotEmpty($lesson->metadata['panda_upload_queued_at']);
        Queue::assertPushed(UploadLessonToPanda::class, fn (UploadLessonToPanda $job): bool => $job->lessonId === $lesson->id && $job->runId === $run->id);
    }

    public function test_panda_upload_job_uploads_queued_lesson_and_updates_run(): void
    {
        config(['services.panda.video_upload_job_delay_seconds' => 0]);

        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $lesson = Lesson::query()->create([
            'title' => '01 - Introdução',
            'slug' => 'aula-01-introducao',
            'description' => 'Aula importada pelo Drive.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'upload_queued',
            'metadata' => [
                'source' => 'google_drive',
                'drive_file_id' => 'drive-video-excel-01',
                'drive_mime_type' => 'video/mp4',
                'panda_folder_id' => 'panda-folder-excel-2016',
            ],
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'root-folder',
            'folder_id' => 'root-folder',
            'status' => 'finished',
            'panda_videos_failed' => 1,
        ]);

        $drive->shouldReceive('downloadFileToPath')
            ->once()
            ->with('drive-video-excel-01', Mockery::type('string'))
            ->andReturnUsing(function (string $fileId, string $path): void {
                file_put_contents($path, 'video-content');
            });
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Introdução', 'panda-folder-excel-2016')
            ->andReturn(null);
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução', 'panda-folder-excel-2016')
            ->andReturn([
                'panda_video_id' => 'panda-video-excel-01',
                'title' => '01 - Introdução',
                'duration_seconds' => 1320,
                'panda_status' => 'DRAFT',
                'panda_embed_url' => 'https://player.test/embed/panda-video-excel-01',
                'panda_player_url' => 'https://player.test/panda-video-excel-01',
                'folder_id' => 'panda-folder-excel-2016',
                'payload' => ['id' => 'panda-video-excel-01'],
            ]);

        (new UploadLessonToPanda($lesson->id, $run->id))->handle(new GoogleDriveTrackImporter($drive, $panda));

        $lesson->refresh();
        $run->refresh();

        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame('panda-video-excel-01', $lesson->panda_video_id);
        $this->assertSame('DRAFT', $lesson->panda_status);
        $this->assertSame(1320, $lesson->duration_seconds);
        $this->assertNull($lesson->metadata['panda_upload_error']);
        $this->assertSame(1, $run->panda_videos_uploaded);
        $this->assertSame(0, $run->panda_videos_failed);
    }

    public function test_panda_status_job_marks_processing_lesson_ready_when_conversion_finishes(): void
    {
        $panda = Mockery::mock(PandaVideoClient::class);
        $lesson = Lesson::query()->create([
            'title' => '01 - Introdução',
            'slug' => 'aula-01-introducao-status',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'panda_video_id' => 'panda-video-excel-01',
            'panda_status' => 'CONVERTING',
            'source_status' => 'panda_processing',
            'metadata' => [
                'panda_folder_id' => 'panda-folder-excel-2016',
            ],
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'root-folder',
            'folder_id' => 'root-folder',
            'status' => 'finished',
            'panda_videos_failed' => 1,
        ]);

        $video = [
            'panda_video_id' => 'panda-video-excel-01',
            'title' => '01 - Introdução',
            'duration_seconds' => 1320,
            'panda_status' => 'CONVERTED',
            'panda_embed_url' => 'https://player.test/embed/panda-video-excel-01',
            'panda_player_url' => 'https://player.test/panda-video-excel-01',
            'folder_id' => 'panda-folder-excel-2016',
            'payload' => ['id' => 'panda-video-excel-01'],
        ];

        $panda->shouldReceive('video')
            ->once()
            ->with('panda-video-excel-01', 'panda-folder-excel-2016')
            ->andReturn($video);
        $panda->shouldReceive('videoIsFailed')->once()->with($video)->andReturn(false);
        $panda->shouldReceive('videoIsReady')->twice()->with($video)->andReturn(true);

        (new SyncPandaVideoStatus($lesson->id, $run->id))->handle($panda);

        $lesson->refresh();
        $run->refresh();

        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertSame('CONVERTED', $lesson->panda_status);
        $this->assertSame(1320, $lesson->duration_seconds);
        $this->assertSame(0, $run->panda_videos_failed);
        $this->assertSame('Vídeo pronto no Panda: 01 - Introdução', $run->latest_message);
    }

    public function test_background_job_runs_drive_import_with_expected_parameters(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'folder_url' => 'https://drive.test/root',
            'status' => 'queued',
        ]);
        $importer = Mockery::mock(GoogleDriveTrackImporter::class);

        $importer->shouldReceive('importFolderSubfoldersAsTracks')
            ->once()
            ->withArgs(function (
                Course $givenCourse,
                CourseModule $givenModule,
                string $folderUrl,
                string $lessonStatus,
                bool $createPandaFolders,
                bool $uploadPandaVideos,
                GoogleDriveImportRun $givenRun,
            ) use ($course, $module, $run): bool {
                return $givenCourse->is($course)
                    && $givenModule->is($module)
                    && $folderUrl === 'https://drive.test/root'
                    && $lessonStatus === 'published'
                    && $createPandaFolders
                    && $uploadPandaVideos
                    && $givenRun->is($run);
            })
            ->andReturn(['tracks' => 1, 'created_lessons' => 0]);

        (new ImportGoogleDriveModuleTracks(
            $course->id,
            $module->id,
            'https://drive.test/root',
            'published',
            runId: $run->id,
        ))->handle($importer);

        $this->assertSame('finished', $run->fresh()->status);
        $this->assertSame('Importação concluída.', $run->fresh()->latest_message);
        $this->assertSame(['tracks' => 1, 'created_lessons' => 0], $run->fresh()->summary);
    }

    public function test_background_job_runs_standalone_lesson_drive_import(): void
    {
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/aulas',
            'status' => 'queued',
        ]);
        $importer = Mockery::mock(GoogleDriveTrackImporter::class);

        $importer->shouldReceive('importFolderFilesAsLessons')
            ->once()
            ->withArgs(function (
                ?Course $givenCourse,
                ?CourseModule $givenModule,
                ?CourseModuleTrack $givenTrack,
                string $folderUrl,
                string $lessonStatus,
                ?string $pandaFolderName,
                bool $createPandaFolder,
                bool $uploadPandaVideos,
                GoogleDriveImportRun $givenRun,
            ) use ($run): bool {
                return $givenCourse === null
                    && $givenModule === null
                    && $givenTrack === null
                    && $folderUrl === 'https://drive.test/aulas'
                    && $lessonStatus === 'draft'
                    && $pandaFolderName === 'Aulas avulsas'
                    && $createPandaFolder
                    && $uploadPandaVideos
                    && $givenRun->is($run);
            })
            ->andReturn(['tracks' => 0, 'total_lessons' => 2, 'created_lessons' => 2]);

        (new ImportGoogleDriveLessons(
            null,
            null,
            null,
            'https://drive.test/aulas',
            pandaFolderName: 'Aulas avulsas',
            runId: $run->id,
        ))->handle($importer);

        $this->assertSame('finished', $run->fresh()->status);
        $this->assertSame('Importação de aulas concluída.', $run->fresh()->latest_message);
        $this->assertSame(['tracks' => 0, 'total_lessons' => 2, 'created_lessons' => 2], $run->fresh()->summary);
    }

    public function test_background_lesson_drive_import_fails_when_folder_has_no_files(): void
    {
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/empty',
            'status' => 'queued',
        ]);
        $importer = Mockery::mock(GoogleDriveTrackImporter::class);

        $importer->shouldReceive('importFolderFilesAsLessons')
            ->once()
            ->andReturn([
                'folder_id' => 'empty',
                'tracks' => 0,
                'created_tracks' => 0,
                'updated_tracks' => 0,
                'total_lessons' => 0,
                'panda_folders' => 0,
                'panda_videos_uploaded' => 0,
                'panda_videos_skipped' => 0,
                'panda_videos_failed' => 0,
                'created_lessons' => 0,
                'updated_lessons' => 0,
            ]);

        (new ImportGoogleDriveLessons(
            null,
            null,
            null,
            'https://drive.test/empty',
            runId: $run->id,
        ))->handle($importer);

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('Nenhum arquivo foi encontrado na pasta do Drive.', $run->fresh()->latest_message);
        $this->assertStringContainsString('conta de serviço', $run->fresh()->error_message);
    }

    public function test_reprocess_pending_lessons_reuses_existing_panda_video_by_title(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldNotReceive('downloadFileToPath');
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Excel 2016', 'panda-folder-excel-2016')
            ->andReturn([
                'panda_video_id' => 'existing-panda-excel-2016',
                'title' => '01-excel-2016.mp4',
                'description' => null,
                'duration_seconds' => 0,
                'thumbnail_url' => null,
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/existing-panda-excel-2016',
                'panda_player_url' => 'https://player.test/existing-panda-excel-2016',
                'folder_id' => 'panda-folder-excel-2016',
                'payload' => ['id' => 'existing-panda-excel-2016'],
            ]);
        $panda->shouldNotReceive('uploadVideo');
        $panda->shouldReceive('findFolderByName')
            ->once()
            ->with('Excel 2016')
            ->andReturn([
                'panda_folder_id' => 'panda-folder-excel-2016',
                'name' => 'Excel 2016',
            ]);

        $sourceRun = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'finished',
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'running',
        ]);
        $lesson = Lesson::query()->create([
            'title' => '01 - Excel 2016',
            'slug' => '01-excel-2016',
            'description' => 'Aula importada pelo Drive.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'awaiting_media',
            'metadata' => [
                'source' => 'google_drive',
                'drive_file_id' => 'drive-video-excel-2016',
                'drive_parent_folder_id' => 'root-folder',
                'drive_source_folder_path' => 'Excel 2016',
                'drive_mime_type' => 'video/mp4',
                'panda_folder_id' => null,
                'panda_upload_error' => 'O upload TUS foi concluído, mas o Panda ainda retornou o vídeo como DRAFT.',
            ],
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->reprocessPendingLessonsForRun($sourceRun, $run);

        $lesson->refresh();

        $this->assertSame(1, $summary['total_lessons']);
        $this->assertSame(0, $summary['panda_videos_uploaded']);
        $this->assertSame(1, $summary['panda_videos_skipped']);
        $this->assertSame(0, $summary['panda_videos_failed']);
        $this->assertSame('existing-panda-excel-2016', $lesson->panda_video_id);
        $this->assertSame('CONVERTED', $lesson->panda_status);
        $this->assertSame('media_ready', $lesson->source_status);
        $this->assertNull($lesson->metadata['panda_upload_error']);
        $this->assertSame(1, $run->fresh()->processed_lessons);
    }

    public function test_reprocess_pending_lessons_includes_stuck_uploading_lessons(): void
    {
        $drive = Mockery::mock(GoogleDriveClient::class);
        $panda = Mockery::mock(PandaVideoClient::class);

        $drive->shouldNotReceive('downloadFileToPath');
        $panda->shouldReceive('findVideoByTitle')
            ->once()
            ->with('01 - Upload Travado', 'panda-folder-travado')
            ->andReturn([
                'panda_video_id' => 'existing-panda-travado',
                'title' => '01 - Upload Travado',
                'duration_seconds' => 1200,
                'panda_status' => 'CONVERTED',
                'panda_embed_url' => 'https://player.test/embed/existing-panda-travado',
                'panda_player_url' => 'https://player.test/existing-panda-travado',
                'folder_id' => 'panda-folder-travado',
                'payload' => ['id' => 'existing-panda-travado'],
            ]);

        $sourceRun = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'finished',
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'running',
        ]);
        $lesson = Lesson::query()->create([
            'title' => '01 - Upload Travado',
            'slug' => '01-upload-travado',
            'description' => 'Aula importada pelo Drive.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'status' => 'published',
            'source_status' => 'uploading',
            'metadata' => [
                'source' => 'google_drive',
                'drive_file_id' => 'drive-upload-travado',
                'drive_parent_folder_id' => 'root-folder',
                'drive_source_folder_path' => 'Travados',
                'drive_mime_type' => 'video/mp4',
                'panda_folder_id' => 'panda-folder-travado',
            ],
        ]);

        $summary = (new GoogleDriveTrackImporter($drive, $panda))->reprocessPendingLessonsForRun($sourceRun, $run);

        $this->assertSame(1, $summary['total_lessons']);
        $this->assertSame(1, $summary['panda_videos_skipped']);
        $this->assertSame('media_ready', $lesson->fresh()->source_status);
        $this->assertSame('existing-panda-travado', $lesson->fresh()->panda_video_id);
    }

    public function test_background_job_runs_pending_lesson_reprocess(): void
    {
        $sourceRun = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'finished',
        ]);
        $run = GoogleDriveImportRun::query()->create([
            'folder_url' => 'https://drive.test/root',
            'folder_id' => 'root-folder',
            'status' => 'queued',
        ]);
        $importer = Mockery::mock(GoogleDriveTrackImporter::class);

        $importer->shouldReceive('reprocessPendingLessonsForRun')
            ->once()
            ->withArgs(fn (GoogleDriveImportRun $givenSourceRun, GoogleDriveImportRun $givenRun): bool => $givenSourceRun->is($sourceRun) && $givenRun->is($run))
            ->andReturn([
                'total_lessons' => 1,
                'panda_videos_uploaded' => 0,
                'panda_videos_skipped' => 1,
                'panda_videos_failed' => 0,
            ]);

        (new ReprocessGoogleDrivePendingLessons($sourceRun->id, $run->id))->handle($importer);

        $this->assertSame('finished', $run->fresh()->status);
        $this->assertSame('Reprocessamento de pendentes concluído.', $run->fresh()->latest_message);
        $this->assertSame(1, $run->fresh()->summary['total_lessons']);
    }
}
