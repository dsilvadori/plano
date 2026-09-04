<?php

namespace Tests\Feature;

use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\StudyTrack;
use App\Models\Teacher;
use App\Services\CourseSpreadsheetImporter;
use App\Services\CourseSpreadsheetParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_course_edit_accepts_stored_spreadsheet_upload_path(): void
    {
        $method = new \ReflectionMethod(EditCourse::class, 'resolveUploadedSpreadsheetPath');
        $method->setAccessible(true);

        $this->assertSame(
            'imports/courses/Administrador.xlsx',
            $method->invoke(null, 'imports/courses/Administrador.xlsx'),
        );
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
        $this->assertCount(8, $course->modules->reject->shouldBeExcludedFromStudyPlan());
        $this->assertCount(1, $course->studyTracks);
        $this->assertSame('Trilha Oficial - Oficial de Administração', $course->studyTracks->first()->name);
        $this->assertCount(9, $course->studyTracks->first()->modules);
        $this->assertTrue($course->modules()->where('course_modules.name', 'Comece por aqui')->exists());
        $this->assertTrue($course->moduleTracks()->where('course_module_tracks.slug', 'instrucoes')->exists());
        $this->assertDatabaseHas('lessons', [
            'title' => 'Comece por aqui',
            'slug' => 'comece-por-aqui',
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $startHereModule = $course->modules()->where('course_modules.name', 'Comece por aqui')->firstOrFail();
        $startHereLesson = Lesson::query()->where('title', 'Comece por aqui')->firstOrFail();
        $this->assertTrue($startHereModule->onlineLessons()->whereKey($startHereLesson->id)->exists());
        $this->assertTrue($course->studyTracks->first()->modules()->whereKey($startHereModule->id)->exists());
        $this->assertDatabaseHas('course_modules', [
            'course_id' => null,
            'name' => 'Informáitca',
            'workload_minutes' => 1332,
            'type' => 'basic',
        ]);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => null,
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
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => $module->lessons[0]['name'],
            'duration_seconds' => $module->lessons[0]['minutes'] * 60,
            'status' => 'published',
            'source_status' => 'awaiting_media',
        ]);
        $lesson = Lesson::query()->where('title', $module->lessons[0]['name'])->firstOrFail();
        $this->assertTrue($module->onlineLessons()->whereKey($lesson->id)->exists());
        $this->assertTrue($track->lessons()->whereKey($lesson->id)->exists());
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
        $course->modules()->attach($existingModule->id, ['sort_order' => 999]);

        $importedCourse = app(CourseSpreadsheetImporter::class)->importInto(
            $course,
            base_path('tests/Fixtures/Imports/Oficial de Administração com aba.xlsx')
        );

        $this->assertTrue($importedCourse->is($course));
        $this->assertSame('Curso Gabaritando CRT', $importedCourse->name);
        $this->assertSame('curso-gabaritando-crt', $importedCourse->slug);
        $this->assertFalse($importedCourse->is_active);
        $this->assertDatabaseMissing('course_modules', [
            'id' => $existingModule->id,
        ]);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => null,
            'name' => 'Português',
            'workload_minutes' => 730,
        ]);
        $this->assertDatabaseHas('study_tracks', [
            'course_id' => $course->id,
            'name' => 'Trilha Oficial - Curso Gabaritando CRT',
        ]);
        $this->assertSame(8, $course->modules()->get()->reject->shouldBeExcludedFromStudyPlan()->count());
        $this->assertSame(9, $course->studyTracks()->where('name', 'Trilha Oficial - Curso Gabaritando CRT')->first()->modules()->count());
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

        $this->assertNotSame($existingImportedModule->id, $updatedModule->id);
        $this->assertSame('basic', $updatedModule->type);
        $this->assertSame(730, $updatedModule->workload_minutes);
        $this->assertNotSame('Conteúdo antigo', $updatedModule->lessons[0]['name']);
        $this->assertTrue($updatedModule->tracks()->where('name', 'Classe de palavras')->exists());
        $this->assertDatabaseMissing('course_modules', [
            'id' => $existingImportedModule->id,
        ]);
        $this->assertDatabaseMissing('course_modules', [
            'id' => $staleModule->id,
        ]);
        $this->assertDatabaseCount('study_tracks', 1);
        $this->assertSame('Trilha Oficial - Nome Antigo', $officialTrack->name);
        $this->assertTrue($officialTrack->modules()->whereKey($updatedModule->id)->exists());
        $this->assertTrue($officialTrack->modules()->where('course_modules.name', 'Comece por aqui')->exists());
        $this->assertSame(9, $officialTrack->modules()->count());
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
        $this->assertDatabaseCount('course_modules', 1);
        $this->assertDatabaseCount('lessons', 0);
    }

    public function test_csv_import_creates_course_modules_and_online_lessons(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'course-import-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,module_teacher_name,track_name,professor,lesson_title,lesson_minutes,lesson_type,lesson_status,panda_video_id,panda_embed_url',
            'Curso CSV,Português,basic,1,Professora do Módulo,Classes de palavras,Dorival Conte Jr.,Classes de palavras,30,video,published,video_1,https://player.example.com/video_1',
            'Curso CSV,Português,basic,1,Professora do Módulo,Classes de palavras,Dorival Conte Jr.,Advérbio,20,video,published,video_2,https://player.example.com/video_2',
            'Curso CSV,Legislação,specific,2,,Legislação,,Lei Orgânica,45,video,draft,video_3,https://player.example.com/video_3',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame('Curso CSV', $course->name);
        $this->assertSame(2, $course->modules()->get()->reject->shouldBeExcludedFromStudyPlan()->count());
        $this->assertSame(2, CourseModuleTrack::query()->whereHas('module', fn ($query) => $query->where('name', '!=', 'Comece por aqui'))->count());
        $this->assertSame(4, $course->linkedLessonsCount());
        $this->assertDatabaseHas('course_modules', [
            'name' => 'Português',
            'teacher_name' => 'Professora do Módulo',
        ]);
        $this->assertDatabaseHas('teachers', [
            'name' => 'Professora do Módulo',
        ]);
        $this->assertDatabaseHas('course_module_tracks', [
            'name' => 'Classes de palavras',
            'teacher_name' => 'Dorival Conte Jr.',
        ]);
        $this->assertDatabaseHas('course_module_track_course', [
            'course_id' => $course->id,
        ]);
        $teacher = Teacher::query()->where('name', 'Dorival Conte Jr.')->first();
        $this->assertNotNull($teacher);
        $this->assertTrue(
            CourseModuleTrack::query()
                ->where('name', 'Classes de palavras')
                ->where('teacher_id', $teacher->id)
                ->exists(),
        );
        $this->assertDatabaseHas('course_module_tracks', [
            'name' => 'Legislação',
            'teacher_name' => null,
        ]);
        $this->assertDatabaseHas('lessons', [
            'course_id' => null,
            'title' => 'Classes de palavras',
            'duration_seconds' => 1800,
            'panda_video_id' => 'video_1',
            'panda_embed_url' => 'https://player.example.com/video_1',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('lessons', [
            'course_id' => null,
            'title' => 'Lei Orgânica',
            'duration_seconds' => 2700,
            'panda_video_id' => 'video_3',
            'panda_embed_url' => 'https://player.example.com/video_3',
            'status' => 'published',
            'source_status' => 'media_ready',
        ]);
    }

    public function test_csv_import_updates_existing_track_teacher_name_when_column_is_present(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'course-import-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,teacher_name,lesson_title,lesson_minutes,lesson_status',
            'Curso CSV,Português,basic,1,Classes de palavras,Dorival Conte Jr.,Classes de palavras,30,published',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->import($path);

            file_put_contents($path, implode("\n", [
                'course_name,module_name,module_type,module_sort_order,track_name,teacher_name,lesson_title,lesson_minutes,lesson_status',
                'Curso CSV,Português,basic,1,Classes de palavras,Prof. Atualizado,Classes de palavras,30,published',
            ]));

            app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseHas('course_module_tracks', [
            'name' => 'Classes de palavras',
            'teacher_name' => 'Prof. Atualizado',
        ]);
        $this->assertDatabaseHas('teachers', [
            'name' => 'Prof. Atualizado',
        ]);
    }

    public function test_csv_import_reuses_registered_teacher_when_normalized_name_matches(): void
    {
        $teacher = Teacher::factory()->create([
            'name' => 'Professor Dorival Conte Jr.',
            'thumbnail_path' => 'teacher-thumbnails/dorival.webp',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'course-import-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,teacher_name,lesson_title,lesson_minutes,lesson_status',
            'Curso CSV,Português,basic,1,Classes de palavras,Prof. Dorival Conte Jr.,Classes de palavras,30,published',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, Teacher::query()->count());
        $this->assertTrue(
            CourseModuleTrack::query()
                ->where('name', 'Classes de palavras')
                ->where('teacher_id', $teacher->id)
                ->exists(),
        );
        $this->assertSame(
            url('/media/thumbnails/teacher-thumbnails/dorival.webp'),
            CourseModuleTrack::query()->where('name', 'Classes de palavras')->firstOrFail()->thumbnail_display_url,
        );
    }

    public function test_reimport_preserves_removed_spreadsheet_lessons_for_manual_review(): void
    {
        $firstPath = tempnam(sys_get_temp_dir(), 'course-import-first-').'.csv';
        $secondPath = tempnam(sys_get_temp_dir(), 'course-import-second-').'.csv';
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
            $removedLesson = $course->linkedLessonsQuery()->where('title', 'Advérbio')->firstOrFail();

            app(CourseSpreadsheetImporter::class)->importInto($course, $secondPath);
        } finally {
            @unlink($firstPath);
            @unlink($secondPath);
        }

        $this->assertSame('published', $removedLesson->fresh()->status);
    }

    public function test_spreadsheet_import_recreates_modules_but_reuses_existing_lessons_by_name(): void
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

        $path = tempnam(sys_get_temp_dir(), 'course-import-reuse-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,lesson_title,lesson_minutes',
            'Curso Novo,Portugues,basic,1,Classes de palavras,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, CourseModule::query()->whereRaw('lower(name) like ?', ['portugu%'])->count());
        $this->assertSame(1, Lesson::query()->where('title', 'Classes de palavras')->count());
        $importedModule = $course->modules()->where('course_modules.name', 'Portugues')->firstOrFail();

        $this->assertFalse($course->modules()->whereKey($existingModule->id)->exists());
        $this->assertNotSame($existingModule->id, $importedModule->id);
        $this->assertTrue($existingModule->onlineLessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($importedModule->onlineLessons()->whereKey($existingLesson->id)->exists());
        $this->assertTrue($course->studyTracks()->first()->modules()->whereKey($importedModule->id)->exists());
        $this->assertSame('panda-original', $existingLesson->fresh()->panda_video_id);
        $this->assertSame('https://player.example.com/original', $existingLesson->fresh()->panda_embed_url);
    }

    public function test_spreadsheet_import_recreates_same_named_module_and_keeps_teacher(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Professora Maria Silva']);
        $libraryCourse = Course::factory()->create(['name' => 'Biblioteca']);
        $existingModule = CourseModule::factory()->create([
            'course_id' => $libraryCourse->id,
            'teacher_id' => $teacher->id,
            'teacher_name' => 'Professora Maria Silva',
            'name' => 'Português',
            'type' => 'basic',
            'workload_minutes' => 30,
        ]);
        $existingTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $existingModule->id,
            'name' => 'Classe de palavras',
            'slug' => 'classe-de-palavras',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $existingLesson = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Substantivo',
            'duration_seconds' => 1800,
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $existingTrack->lessons()->attach($existingLesson->id, ['sort_order' => 1]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-clone-module-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Novo,Portugues,basic,1,Análise sintática,Sujeito e predicado,40',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(2, CourseModule::query()->whereRaw('lower(name) like ?', ['portugu%'])->count());
        $this->assertFalse($course->modules()->whereKey($existingModule->id)->exists());

        $importedModule = $course->modules()->where('course_modules.name', 'Portugues')->firstOrFail();

        $this->assertNotSame($existingModule->id, $importedModule->id);
        $this->assertSame($teacher->id, $importedModule->teacher_id);
        $this->assertSame('Professora Maria Silva', $importedModule->teacher_name);
        $this->assertNull($importedModule->metadata['cloned_from_module_id'] ?? null);
        $this->assertTrue($importedModule->tracks()->where('name', 'Análise sintática')->exists());
        $this->assertFalse($existingModule->tracks()->where('name', 'Análise sintática')->exists());
        $this->assertTrue($existingModule->tracks()->whereKey($existingTrack)->exists());
    }

    public function test_spreadsheet_import_keeps_compound_tracks_inside_new_spreadsheet_module(): void
    {
        $classesModule = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Português - Classe de palavras',
            'type' => 'basic',
            'workload_minutes' => 50,
            'lessons' => [
                ['name' => 'Substantivo', 'minutes' => 25],
                ['name' => 'Adjetivo', 'minutes' => 25],
            ],
        ]);
        $syntaxModule = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Português - Análise sintática',
            'type' => 'basic',
            'workload_minutes' => 40,
            'lessons' => [
                ['name' => 'Sujeito', 'minutes' => 20],
                ['name' => 'Predicado', 'minutes' => 20],
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-compound-modules-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,track_sort_order,lesson_title,lesson_minutes',
            'Curso Português,Português,basic,1,Classe de palavras,1,Substantivo,25',
            'Curso Português,Português,basic,1,Análise sintática,2,Sujeito,20',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $importedModule = $course->modules()->where('name', 'Português')->firstOrFail();

        $this->assertFalse($course->modules()->whereKey($classesModule->id)->exists());
        $this->assertFalse($course->modules()->whereKey($syntaxModule->id)->exists());
        $this->assertTrue($course->studyTracks()->first()->modules()->whereKey($importedModule->id)->exists());
        $this->assertTrue($importedModule->tracks()->where('name', 'Classe de palavras')->exists());
        $this->assertTrue($importedModule->tracks()->where('name', 'Análise sintática')->exists());
        $this->assertFalse($classesModule->fresh()->tracks()->where('name', 'Classe de palavras')->exists());
        $this->assertFalse($syntaxModule->fresh()->tracks()->where('name', 'Análise sintática')->exists());
        $this->assertDatabaseHas('lessons', [
            'course_id' => null,
            'title' => 'Substantivo',
            'duration_seconds' => 1500,
        ]);
    }

    public function test_xlsx_sheet_module_keeps_tracks_inside_sheet_module_even_when_compound_module_exists(): void
    {
        $course = Course::factory()->create(['name' => 'Administrador']);
        $compoundModule = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Conhecimentos Específicos - Teorias da Administração',
            'type' => 'specific',
            'workload_minutes' => 1,
            'is_active' => true,
        ]);
        $payload = [
            'modules' => [[
                'sheet_name' => 'Conhecimentos Especificos',
                'group_name' => 'Conhecimentos Específicos - Administrador - Santos',
                'name' => 'Conhecimentos Específicos - Administrador - Santos',
                'type' => 'specific',
                'workload_minutes' => 41,
                'sort_order' => 1,
                'tracks' => [[
                    'name' => 'Teorias da Administração',
                    'sort_order' => 1,
                    'workload_minutes' => 41,
                    'lessons' => [
                        ['name' => '01 - Teorias da Administração - Teoria Científica', 'minutes' => 23],
                        ['name' => '02 - Teorias da Administração - Teoria Clássica', 'minutes' => 18],
                    ],
                ]],
                'lessons' => [
                    ['name' => '01 - Teorias da Administração - Teoria Científica', 'minutes' => 23],
                    ['name' => '02 - Teorias da Administração - Teoria Clássica', 'minutes' => 18],
                ],
            ]],
        ];

        $method = new \ReflectionMethod(CourseSpreadsheetImporter::class, 'importStructure');
        $method->setAccessible(true);
        $method->invoke(app(CourseSpreadsheetImporter::class), $course, $payload, 'Trilha Oficial - Administrador');

        $module = $course->modules()->where('name', 'Conhecimentos Específicos - Administrador - Santos')->firstOrFail();

        $this->assertFalse($course->modules()->whereKey($compoundModule->id)->exists());
        $this->assertTrue($module->tracks()->where('name', 'Teorias da Administração')->exists());
        $this->assertSame(1, $course->modules()->get()->reject->shouldBeExcludedFromStudyPlan()->count());
        $this->assertSame(1, $module->tracks()->count());
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

        $path = tempnam(sys_get_temp_dir(), 'course-import-link-standalone-').'.csv';
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

    public function test_spreadsheet_import_keeps_lesson_published_when_media_is_missing(): void
    {
        $existingLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'Aula sem mídia',
            'slug' => 'aula-sem-midia',
            'description' => 'Aula antiga publicada sem mídia.',
            'type' => 'video',
            'duration_seconds' => 900,
            'sort_order' => 1,
            'source_status' => 'media_ready',
            'status' => 'published',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-missing-media-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Sem Mídia,Português,basic,1,Classes de palavras,Aula sem mídia,15',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $existingLesson->refresh();

        $this->assertSame('published', $existingLesson->status);
        $this->assertSame('awaiting_media', $existingLesson->source_status);
    }

    public function test_spreadsheet_import_publishes_lesson_automatically_when_media_is_present(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'course-import-ready-media-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes,lesson_status,panda_video_id,panda_embed_url',
            'Curso Com Mídia,Português,basic,1,Classes de palavras,Aula com mídia,15,draft,panda-aula-com-midia,https://player.example.com/aula-com-midia',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $lesson = Lesson::query()->where('title', 'Aula com mídia')->firstOrFail();

        $this->assertSame('published', $lesson->status);
        $this->assertSame('media_ready', $lesson->source_status);
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

        $path = tempnam(sys_get_temp_dir(), 'course-import-link-approximate-').'.csv';
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

    public function test_spreadsheet_import_uses_duration_when_matching_approximate_lesson_names(): void
    {
        $shortLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01 - Recursos e configurações do Windows 10',
            'slug' => 'recursos-configuracoes-windows-10-curta',
            'description' => 'Aula parecida, mas curta demais.',
            'type' => 'video',
            'duration_seconds' => 600,
            'sort_order' => 1,
            'panda_video_id' => 'panda-windows-curta',
            'panda_embed_url' => 'https://player.example.com/windows-curta',
            'source_status' => 'media_ready',
            'status' => 'published',
            'metadata' => [
                'source' => 'google_drive',
                'drive_source_folder_path' => 'Windows 10',
            ],
        ]);
        $rightDurationLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => 'AULA 09 - recursos_windows_10_configuracoes (720p)',
            'slug' => 'recursos-configuracoes-windows-10-certa',
            'description' => 'Aula parecida e com duração compatível.',
            'type' => 'video',
            'duration_seconds' => 1800,
            'sort_order' => 2,
            'panda_video_id' => 'panda-windows-certa',
            'panda_embed_url' => 'https://player.example.com/windows-certa',
            'source_status' => 'media_ready',
            'status' => 'published',
            'metadata' => [
                'source' => 'google_drive',
                'drive_source_folder_path' => 'Windows 10',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-duration-match-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Match Duracao,Informática,basic,1,Windows 10,01 - Recursos e configurações do Windows 10,30',
        ]));

        try {
            $course = app(CourseSpreadsheetImporter::class)->import($path);
        } finally {
            @unlink($path);
        }

        $track = $course->modules()->where('name', 'Informática')->firstOrFail()
            ->tracks()->where('name', 'Windows 10')->firstOrFail();

        $this->assertTrue($track->lessons()->whereKey($rightDurationLesson->id)->exists());
        $this->assertFalse($track->lessons()->whereKey($shortLesson->id)->exists());
        $this->assertTrue((bool) data_get($rightDurationLesson->fresh()->metadata, 'matched_by_duration'));
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

        $path = tempnam(sys_get_temp_dir(), 'course-import-ready-priority-').'.csv';
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

    public function test_spreadsheet_import_promotes_ready_media_lesson_over_course_placeholder(): void
    {
        $course = Course::factory()->create(['name' => 'Curso Placeholder', 'slug' => 'curso-placeholder']);
        $module = CourseModule::factory()->for($course)->create([
            'name' => 'Informática',
            'type' => 'basic',
            'workload_minutes' => 30,
            'sort_order' => 1,
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $track->courses()->attach($course->id, ['sort_order' => 1]);

        $placeholder = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => 'Aula de Segurança',
            'slug' => 'aula-de-seguranca',
            'description' => 'Placeholder sem mídia.',
            'type' => 'video',
            'duration_seconds' => 1800,
            'sort_order' => 1,
            'source_status' => 'awaiting_media',
            'status' => 'draft',
        ]);

        $readyLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01 - Aula de Segurança',
            'slug' => 'aula-de-seguranca-com-midia',
            'description' => 'Aula com mídia pronta.',
            'type' => 'video',
            'duration_seconds' => 1800,
            'sort_order' => 9,
            'panda_video_id' => 'panda-seguranca',
            'panda_embed_url' => 'https://player.example.com/seguranca',
            'source_status' => 'media_ready',
            'status' => 'published',
            'metadata' => [
                'source' => 'google_drive',
                'drive_source_folder_path' => 'Windows 10',
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-promote-media-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Placeholder,Informática,basic,1,Windows 10,Aula de Segurança,30',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->importInto($course, $path);
        } finally {
            @unlink($path);
        }

        $readyLesson->refresh();
        $importedTrack = $course->modules()->where('name', 'Informática')->firstOrFail()
            ->tracks()->where('name', 'Windows 10')->firstOrFail();

        $this->assertNull($readyLesson->course_id);
        $this->assertNull($readyLesson->course_module_id);
        $this->assertNull($readyLesson->course_module_track_id);
        $this->assertSame('panda-seguranca', $readyLesson->panda_video_id);
        $this->assertSame('media_ready', $readyLesson->source_status);
        $this->assertSame('published', $readyLesson->status);
        $this->assertTrue($importedTrack->lessons()->whereKey($readyLesson->id)->exists());
        $this->assertFalse($importedTrack->lessons()->whereKey($placeholder->id)->exists());
        $this->assertSame(1, $importedTrack->lessons()->count());
    }

    public function test_spreadsheet_import_reuses_ready_lesson_without_moving_it_into_module_when_slug_conflicts(): void
    {
        $course = Course::factory()->create(['name' => 'Curso Reuso', 'slug' => 'curso-reuso']);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => 'Direito Constitucional',
            'type' => 'basic',
            'workload_minutes' => 30,
            'sort_order' => 1,
        ]);
        $course->modules()->attach($module->id, ['sort_order' => 1]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Conceitos Iniciais',
            'slug' => 'conceitos-iniciais',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $placeholder = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Conceitos Iniciais',
            'slug' => '01-conceitos-iniciais',
            'description' => 'Placeholder sem mídia.',
            'type' => 'video',
            'duration_seconds' => 1800,
            'sort_order' => 1,
            'source_status' => 'awaiting_media',
            'status' => 'draft',
        ]);

        $readyLesson = Lesson::query()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => '01 - Conceitos Iniciais',
            'slug' => '01-conceitos-iniciais',
            'description' => 'Aula pronta.',
            'type' => 'video',
            'duration_seconds' => 1800,
            'sort_order' => 1,
            'panda_video_id' => 'panda-conceitos-iniciais',
            'panda_embed_url' => 'https://player.example.com/conceitos-iniciais',
            'source_status' => 'media_ready',
            'status' => 'published',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'course-import-slug-conflict-').'.csv';
        file_put_contents($path, implode("\n", [
            'course_name,module_name,module_type,module_sort_order,track_name,lesson_title,lesson_minutes',
            'Curso Reuso,Direito Constitucional,basic,1,Conceitos Iniciais,01 - Conceitos Iniciais,30',
        ]));

        try {
            app(CourseSpreadsheetImporter::class)->importInto($course, $path);
        } finally {
            @unlink($path);
        }

        $readyLesson->refresh();
        $importedModule = $course->modules()->where('name', 'Direito Constitucional')->firstOrFail();
        $importedTrack = $importedModule->tracks()->where('name', 'Conceitos Iniciais')->firstOrFail();

        $this->assertNull($readyLesson->course_id);
        $this->assertNull($readyLesson->course_module_id);
        $this->assertNull($readyLesson->course_module_track_id);
        $this->assertTrue($importedModule->onlineLessons()->whereKey($readyLesson->id)->exists());
        $this->assertTrue($importedTrack->lessons()->whereKey($readyLesson->id)->exists());
        $this->assertFalse($importedTrack->lessons()->whereKey($placeholder->id)->exists());
    }

    public function test_catalog_detach_course_bindings_preserves_legacy_direct_links_in_pivots(): void
    {
        $course = Course::factory()->create(['name' => 'Curso Legado']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Módulo Legado',
            'sort_order' => 3,
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Trilha Legada',
            'slug' => 'trilha-legada',
            'sort_order' => 2,
            'status' => 'published',
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => 'Aula Legada',
            'slug' => 'aula-legada',
            'type' => 'video',
            'duration_seconds' => 600,
            'sort_order' => 4,
            'status' => 'published',
            'source_status' => 'media_ready',
        ]);

        Artisan::call('catalog:detach-course-bindings');

        $this->assertDatabaseHas('course_module_course', [
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'sort_order' => 3,
        ]);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $lesson->id,
            'sort_order' => 4,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $lesson->id,
            'sort_order' => 4,
        ]);
        $this->assertNull($module->fresh()->course_id);
        $this->assertNull($lesson->fresh()->course_id);
        $this->assertNull($lesson->fresh()->course_module_id);
        $this->assertNull($lesson->fresh()->course_module_track_id);
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

        $path = tempnam(sys_get_temp_dir(), 'course-import-track-context-').'.csv';
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
