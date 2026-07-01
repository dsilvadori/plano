<?php

namespace Tests\Feature;

use App\Jobs\ImportGoogleDriveModuleTracks;
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
                            'name' => '02 - Explorador de arquivos.pdf',
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
        $this->assertSame(2, $windowsTrack->lessons()->count());
        $this->assertDatabaseHas('lessons', [
            'course_module_id' => $module->id,
            'course_module_track_id' => $windowsTrack->id,
            'title' => '01 - Windows 10',
            'type' => 'text',
            'status' => 'draft',
            'source_status' => 'awaiting_media',
            'google_doc_url' => 'https://drive.test/docs/windows-01',
        ]);
        $this->assertDatabaseHas('lessons', [
            'title' => '02 - Explorador de arquivos',
            'type' => 'pdf',
            'source_status' => 'media_ready',
        ]);
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
            ->with('Informática')
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
        $panda->shouldReceive('uploadVideo')
            ->once()
            ->with(Mockery::type('string'), '01 - Introdução ao Windows', 'panda-track-windows-10')
            ->andReturn([
                'panda_video_id' => 'panda-video-windows-01',
                'title' => '01 - Introdução ao Windows',
                'description' => null,
                'duration_seconds' => 0,
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
            ->with('Informática')
            ->andReturn(['panda_folder_id' => 'panda-module-informatica', 'name' => 'Informática']);
        $panda->shouldReceive('findOrCreateFolder')
            ->once()
            ->with('Windows 10', 'panda-module-informatica')
            ->andReturn(['panda_folder_id' => 'panda-track-windows-10', 'name' => 'Windows 10']);
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
}
