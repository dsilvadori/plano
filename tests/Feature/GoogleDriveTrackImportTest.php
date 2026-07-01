<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Services\GoogleDriveTrackImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            'services.panda.folder_parent_payload_key' => 'parent_id',
            'services.panda.folder_parent_query_param' => 'parent_id',
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
                    return Http::response(['data' => []], 200);
                }

                $payload = $request->data();

                return Http::response([
                    'id' => isset($payload['parent_id']) ? 'panda-track-windows-10' : 'panda-module-informatica',
                    'name' => $payload['name'],
                    'parent_id' => $payload['parent_id'] ?? null,
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
}
