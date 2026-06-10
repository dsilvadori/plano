<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
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
        $this->assertCount(30, $payload['modules']);
        $this->assertSame('Português - Classe de palavras', $payload['modules'][0]['name']);
        $this->assertSame(137, $payload['modules'][0]['workload_minutes']);
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

        $course->load(['modules', 'studyTracks.modules']);

        $this->assertSame('Oficial de Administração', $course->name);
        $this->assertCount(30, $course->modules);
        $this->assertCount(1, $course->studyTracks);
        $this->assertSame('Trilha Oficial - Oficial de Administração', $course->studyTracks->first()->name);
        $this->assertCount(30, $course->studyTracks->first()->modules);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'name' => 'Informáitca - Excel 2016',
            'workload_minutes' => 380,
            'type' => 'basic',
        ]);
        $this->assertDatabaseHas('course_modules', [
            'course_id' => $course->id,
            'name' => 'Legislação - L.O - Santos',
            'workload_minutes' => 207,
            'type' => 'specific',
        ]);
        $module = $course->modules()->where('name', 'Português - Classe de palavras')->first();
        $this->assertNotNull($module);
        $this->assertIsArray($module->lessons);
        $this->assertNotEmpty($module->lessons);
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
            'name' => 'Português - Classe de palavras',
            'workload_minutes' => 137,
        ]);
        $this->assertDatabaseHas('study_tracks', [
            'course_id' => $course->id,
            'name' => 'Trilha Oficial - Curso Gabaritando CRT',
        ]);
        $this->assertSame(31, $course->modules()->count());
        $this->assertSame(30, $course->studyTracks()->where('name', 'Trilha Oficial - Curso Gabaritando CRT')->first()->modules()->count());
    }

    public function test_importer_updates_existing_official_structure_when_importing_into_course(): void
    {
        $course = Course::factory()->create([
            'name' => 'Curso Gabaritando CRT',
            'slug' => 'curso-gabaritando-crt',
            'is_active' => false,
        ]);
        $existingImportedModule = CourseModule::factory()->for($course)->create([
            'name' => 'Português - Classe de palavras',
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

        $updatedModule = $course->modules()->where('name', 'Português - Classe de palavras')->first();
        $officialTrack->refresh();

        $this->assertSame($existingImportedModule->id, $updatedModule->id);
        $this->assertSame('basic', $updatedModule->type);
        $this->assertSame(137, $updatedModule->workload_minutes);
        $this->assertNotSame('Conteúdo antigo', $updatedModule->lessons[0]['name']);
        $this->assertDatabaseCount('study_tracks', 1);
        $this->assertSame('Trilha Oficial - Nome Antigo', $officialTrack->name);
        $this->assertTrue($officialTrack->modules()->whereKey($updatedModule->id)->exists());
        $this->assertFalse($officialTrack->modules()->whereKey($staleModule->id)->exists());
        $this->assertSame(30, $officialTrack->modules()->count());
    }
}
