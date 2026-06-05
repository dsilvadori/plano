<?php

namespace Tests\Feature;

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
    }
}
