<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyTrack;
use App\Services\CourseSpreadsheetImporter;
use App\Services\CourseSpreadsheetParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_prefers_course_name_sheet_when_available(): void
    {
        $payload = app(CourseSpreadsheetParser::class)->parse(
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $this->assertSame('Oficial de Administração', $payload['course_name']);
        $this->assertSame('oficial-de-administracao', $payload['course_slug']);
        $this->assertCount(8, $payload['modules']);
        $this->assertSame('Português', $payload['modules'][0]['name']);
        $this->assertCount(8, $payload['modules'][0]['tracks']);
        $this->assertSame('Classe de palavras', $payload['modules'][0]['tracks'][0]['name']);
        $this->assertSame(137, $payload['modules'][0]['tracks'][0]['workload_minutes']);
    }

    public function test_parser_falls_back_to_filename_when_course_name_sheet_is_missing(): void
    {
        $payload = app(CourseSpreadsheetParser::class)->parse(
            base_path('tests/Fixtures/Imports/Oficial de Administração.xlsx')
        );

        $this->assertSame('Oficial de Administração', $payload['course_name']);
        $this->assertSame('Trilha Oficial - Oficial de Administração', $payload['study_track_name']);
    }

    public function test_parser_detects_complementary_module_type(): void
    {
        $parser = app(CourseSpreadsheetParser::class);
        $reflection = new \ReflectionClass($parser);
        $method = $reflection->getMethod('inferModuleType');
        $method->setAccessible(true);

        $type = $method->invoke($parser, 'Conhecimentos Complementares', 'Módulo - Apoio', 'Trilha - Informática complementar');

        $this->assertSame('complementary', $type);
    }

    public function test_importer_creates_course_modules_and_official_study_track(): void
    {
        $course = app(CourseSpreadsheetImporter::class)->import(
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $course->load(['modules.tracks.lessons', 'studyTracks.modules']);

        $this->assertSame('Oficial de Administração', $course->name);
        $this->assertCount(8, $course->modules);
        $this->assertCount(1, $course->studyTracks);
        $this->assertSame('Trilha Oficial - Oficial de Administração', $course->studyTracks->first()->name);
        $this->assertCount(8, $course->studyTracks->first()->modules);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'name' => 'Informáitca',
            'workload_minutes' => 1332,
            'type' => 'basic',
        ]);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'name' => 'Legislação',
            'workload_minutes' => 354,
            'type' => 'specific',
        ]);
        $module = $course->modules()->where('name', 'Português')->first();
        $this->assertNotNull($module);
        $this->assertIsArray($module->lessons);
        $this->assertNotEmpty($module->lessons);
        $track = $module->tracks()->where('name', 'Classe de palavras')->first();
        $this->assertNotNull($track);
        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => $module->lessons[0]['name'],
            'duration_seconds' => $module->lessons[0]['minutes'] * 60,
            'status' => 'draft',
            'source_status' => 'awaiting_media',
        ]);
    }

    public function test_importer_can_add_spreadsheet_structure_to_existing_course(): void
    {
        $course = Course::factory()->create([
            'name' => 'Curso Gabaritando CRT',
            'slug' => 'curso-gabaritando-crt',
            'is_active' => false,
        ]);
        $existingModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo criado manualmente',
            'sort_order' => 999,
        ]);

        $importedCourse = app(CourseSpreadsheetImporter::class)->importInto(
            $course,
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $this->assertTrue($importedCourse->is($course));
        $this->assertSame('Curso Gabaritando CRT', $importedCourse->name);
        $this->assertSame('curso-gabaritando-crt', $importedCourse->slug);
        $this->assertFalse($importedCourse->is_active);
        $this->assertDatabaseHas('course_modules', [
            'id' => $existingModule->id,
            'course_id' => $course->id,
            'name' => 'Módulo criado manualmente',
        ]);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'name' => 'Português',
            'workload_minutes' => 730,
        ]);
        $this->assertDatabaseHas('study_tracks', [
            'course_id' => $course->id,
            'name' => 'Trilha Oficial - Curso Gabaritando CRT',
        ]);
        $this->assertSame(9, $course->modules()->count());
        $this->assertSame(8, $course->studyTracks()->where('name', 'Trilha Oficial - Curso Gabaritando CRT')->first()->modules()->count());
    }

    public function test_importer_updates_existing_official_structure_when_importing_into_course(): void
    {
        $course = Course::factory()->create([
            'name' => 'Curso Gabaritando CRT',
            'slug' => 'curso-gabaritando-crt',
            'is_active' => false,
        ]);
        $existingImportedModule = CourseModule::factory()->for($course)->create([
            'name' => 'Português',
            'type' => 'questions',
            'workload_minutes' => 1,
            'sort_order' => 999,
            'lessons' => [
                ['name' => 'Conteúdo antigo', 'minutes' => 1],
            ],
        ]);
        $staleModule = CourseModule::factory()->for($course)->create([
            'name' => 'Módulo antigo removido da planilha',
            'sort_order' => 998,
        ]);
        $officialTrack = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Nome Antigo',
        ]);
        $officialTrack->modules()->attach([
            $existingImportedModule->id => ['weight' => 1, 'sort_order' => 999],
            $staleModule->id => ['weight' => 1, 'sort_order' => 998],
        ]);

        app(CourseSpreadsheetImporter::class)->importInto(
            $course,
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $updatedModule = $course->modules()->where('name', 'Português')->first();
        $officialTrack->refresh();

        $this->assertSame($existingImportedModule->id, $updatedModule->id);
        $this->assertSame('basic', $updatedModule->type);
        $this->assertSame(730, $updatedModule->workload_minutes);
        $this->assertNotSame('Conteúdo antigo', $updatedModule->lessons[0]['name']);
        $this->assertTrue($updatedModule->tracks()->where('name', 'Classe de palavras')->exists());
        $this->assertDatabaseCount('study_tracks', 1);
        $this->assertSame('Trilha Oficial - Nome Antigo', $officialTrack->name);
        $this->assertTrue($officialTrack->modules()->whereKey($updatedModule->id)->exists());
        $this->assertFalse($officialTrack->modules()->whereKey($staleModule->id)->exists());
        $this->assertSame(8, $officialTrack->modules()->count());
    }

    public function test_importer_preview_reports_dry_run_counts_without_writing(): void
    {
        $preview = app(CourseSpreadsheetImporter::class)->preview(
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $this->assertSame('create', $preview['course']['action']);
        $this->assertSame(8, $preview['modules']['total']);
        $this->assertSame(8, $preview['modules']['create']);
        $this->assertSame(0, $preview['modules']['update']);
        $this->assertGreaterThan(0, $preview['lessons']['total']);
        $this->assertSame($preview['lessons']['total'], $preview['lessons']['create']);
        $this->assertDatabaseCount('courses', 0);
        $this->assertDatabaseCount('course_modules', 0);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_csv_import_creates_course_modules_and_online_lessons(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'course-import-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,lesson_title,lesson_minutes,lesson_type,lesson_status,panda_video_id,panda_embed_url',
            'Curso CSV,Português,basic,1,Classes de palavras,30,video,published,video_1,https://player.example.com/video_1',
            'Curso CSV,Português,basic,1,Advérbio,20,video,published,video_2,https://player.example.com/video_2',
            'Curso CSV,Legislação,specific,2,Lei Orgânica,45,video,draft,video_3,https://player.example.com/video_3',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('Curso CSV', $course->name);
        $this->assertSame(2, $course->modules()->count());
        $this->assertSame(2, CourseModuleTrack::query()->count());
        $this->assertSame(3, $course->lessons()->count());
        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'title' => 'Classes de palavras',
            'duration_seconds' => 1800,
            'panda_video_id' => 'video_1',
            'panda_embed_url' => 'https://player.example.com/video_1',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('lessons', [
            'course_id' => $course->id,
            'title' => 'Lei Orgânica',
            'duration_seconds' => 2700,
            'status' => 'draft',
        ]);
    }

    public function test_reimport_preserves_removed_spreadsheet_lessons_for_manual_review(): void
    {
        $firstPath = tempnam(sys_get_temp_dir(), 'course-import-first-') . '.csv';
        $secondPath = tempnam(sys_get_temp_dir(), 'course-import-second-') . '.csv';
        file_put_contents($firstPath, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,lesson_title,lesson_minutes',
            'Curso CSV,Português,basic,1,Classes de palavras,30',
            'Curso CSV,Português,basic,1,Advérbio,20',
        ]));
        file_put_contents($secondPath, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,lesson_title,lesson_minutes',
            'Curso CSV,Português,basic,1,Classes de palavras,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($firstPath);
            $removedLesson = $course->lessons()->where('title', 'Advérbio')->firstOrFail();

            app(CourseSpreadsheetImporter::class)->importInto($course, $secondPath);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $this->assertSame('draft', $removedLesson->fresh()->status);
    }

    public function test_spreadsheet_import_reuses_existing_modules_and_lessons_by_name(): void
    {
        $libraryCourse = Course::factory()->create(['name' => 'Biblioteca']);
        $existingModule = CourseModule::factory()->create([
            'course_id' => $libraryCourse->id,
            'name' => 'Português',
            'type' => 'basic',
            'workload_minutes' => 30,
        ]);
        $existingLesson = Lesson::factory()->create([
            'course_id' => $libraryCourse->id,
            'course_module_id' => $existingModule->id,
            'title' => 'Classes de palavras',
            'slug' => 'classes-de-palavras',
            'duration_seconds' => 1800,
            'panda_video_id' => 'panda-original',
            'panda_embed_url' => 'https://player.example.com/original',
            'status' => 'published',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-reuse-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,lesson_title,lesson_minutes',
            'Curso Novo,Portugues,basic,1,Classes de palavras,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, CourseModule::query()->whereRaw('lower(name) like ?', ['portugu%'])->count());
        $this->assertSame(1, Lesson::query()->where('title', 'Classes de palavras')->count());
        $this->assertTrue($course->modules()->whereKey($existingModule->id)->exists());
        $this->assertTrue($existingModule->onlineLessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($course->studyTracks()->first()->modules()->whereKey($existingModule->id)->exists());
        $this->assertSame('panda-original', $existingLesson->fresh()->panda_video_id);
        $this->assertSame('https://player.example.com/original', $existingLesson->fresh()->panda_embed_url);
    }

    public function test_spreadsheet_import_links_standalone_lessons_by_name_without_overwriting_media(): void
    {
        $existingLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01___aula_avulsa_do_drive (720p)',
            'slug' => '01-aula-avulsa-do-drive',
            'description' => 'Aula importada pelo Drive.',
            'type' => 'video',
            'thumbnail_url' => null,
            'duration_seconds' => 1200,
            'sort_order' => 1,
            'panda_video_id' => 'panda-drive-video',
            'panda_embed_url' => 'https://player.example.com/drive-video',
            'panda_player_url' => null,
            'panda_status' => 'CONVERTED',
            'source_status' => 'media_ready',
            'status' => 'published',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-link-standalone-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso com Aula Avulsa,Informática,basic,1,Windows 10,Aula avulsa do Drive,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $module = $course->modules()->where('name', 'Informática')->firstOrFail();
        $track = $module->tracks()->where('name', 'Windows 10')->firstOrFail();
        $existingLesson->refresh();

        $this->assertSame(1, Lesson::query()->where('panda_video_id', 'panda-drive-video')->count());
        $this->assertNull($existingLesson->course_id);
        $this->assertNull($existingLesson->course_module_id);
        $this->assertNull($existingLesson->course_module_track_id);
        $this->assertSame('panda-drive-video', $existingLesson->panda_video_id);
        $this->assertSame('https://player.example.com/drive-video', $existingLesson->panda_embed_url);
        $this->assertSame('media_ready', $existingLesson->source_status);
        $this->assertTrue($module->onlineLessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($track->lessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($course->studyTracks()->first()->modules()->whereKey($module->id)->exists());
    }

    public function test_spreadsheet_import_links_lessons_by_approximate_name_ignoring_numbering(): void
    {
        $existingLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'AULA 09 - recursos_windows_10_configuracoes (720p)',
            'slug' => 'aula-09-recursos-windows-10-configuracoes',
            'description' => 'Aula importada pelo Drive.',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 9,
            'panda_video_id' => 'panda-windows-recursos',
            'panda_embed_url' => 'https://player.example.com/windows-recursos',
            'panda_status' => 'CONVERTED',
            'source_status' => 'media_ready',
            'status' => 'published',
            'metadata' => [
                'source' => 'google_drive',
                'drive_source_folder_path' => 'Windows 10',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-link-approximate-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso com Match Aproximado,Informática,basic,1,Windows 10,01 - Recursos e configurações do Windows 10,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $module = $course->modules()->where('name', 'Informática')->firstOrFail();
        $track = $module->tracks()->where('name', 'Windows 10')->firstOrFail();
        $existingLesson->refresh();

        $this->assertSame(1, Lesson::query()->where('panda_video_id', 'panda-windows-recursos')->count());
        $this->assertTrue($module->onlineLessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($track->lessons()->whereKey($existingLesson->id)->exists());
        $this->assertSame('panda-windows-recursos', $existingLesson->panda_video_id);
        $this->assertSame('media_ready', $existingLesson->source_status);
        $this->assertSame('published', $existingLesson->status);
    }

    public function test_spreadsheet_import_prefers_ready_media_when_matching_existing_lessons(): void
    {
        Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Guia Inserir',
            'slug' => 'guia-inserir-pendente',
            'description' => 'Aula pendente.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 1,
            'source_status' => 'upload_queued',
            'status' => 'published',
        ]);
        $readyLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Guia Inserir',
            'slug' => 'guia-inserir-pronta',
            'description' => 'Aula pronta.',
            'type' => 'video',
            'duration_seconds' => 1200,
            'sort_order' => 2,
            'panda_video_id' => 'panda-ready-guia-inserir',
            'panda_embed_url' => 'https://player.example.com/ready-guia-inserir',
            'source_status' => 'media_ready',
            'status' => 'published',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-ready-priority-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Prioridade Midia,Informática,basic,1,Excel 2016,Guia Inserir,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $track = $course->modules()->where('name', 'Informática')->firstOrFail()
            ->tracks()->where('name', 'Excel 2016')->firstOrFail();

        $this->assertTrue($track->lessons()->whereKey($readyLesson->id)->exists());
        $this->assertSame('published', $readyLesson->fresh()->status);
        $this->assertSame(1, $track->lessons()->count());
    }

    public function test_spreadsheet_import_uses_track_context_for_generic_lesson_titles(): void
    {
        $excelLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '02 - Aula 2 - Guia Inserir',
            'slug' => 'aula-2-guia-inserir',
            'description' => 'Aula de Excel.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 2,
            'source_status' => 'upload_queued',
            'status' => 'published',
            'metadata' => [
                'drive_source_folder_path' => 'OFFICE 2016 - NOVAS/02 - Excel 2016',
            ],
        ]);
        $pptLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '03 - Aula 3 - Guia Inserir',
            'slug' => 'aula-3-guia-inserir',
            'description' => 'Aula de Power Point.',
            'type' => 'video',
            'duration_seconds' => 0,
            'sort_order' => 3,
            'source_status' => 'upload_queued',
            'status' => 'published',
            'metadata' => [
                'drive_source_folder_path' => 'OFFICE 2016 - NOVAS/03 - PPT 2016',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-track-context-') . '.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Contexto Trilha,Informática,basic,1,Power Point 2016,Guia Inserir,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $track = $course->modules()->where('name', 'Informática')->firstOrFail()
            ->tracks()->where('name', 'Power Point 2016')->firstOrFail();

        $this->assertTrue($track->lessons()->whereKey($pptLesson->id)->exists());
        $this->assertFalse($track->lessons()->whereKey($excelLesson->id)->exists());
        $this->assertSame(1, $track->lessons()->count());
    }
}
