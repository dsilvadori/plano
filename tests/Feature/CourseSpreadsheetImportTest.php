<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\StudyTrack;
use App\Models\User;
use App\Services\CourseSpreadsheetImporter;
use App\Services\CourseSpreadsheetParser;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    public function test_parser_imports_track_row_with_minutes_as_single_lesson(): void
    {
        $path = $this->makeWorkbook([
            'Nome do Curso' => [
                ['Secretário de Unidade Escolar'],
            ],
            'Conhecimentos Básicos' => [
                ['Horas aulas'],
                ['Módulo -Conhecimentos Básicos de Legislação Municipal e Serviço Público'],
                ['Trilha - Estatuto Santos'],
                ['Aula', 'Tempo de aula'],
                ['1 - Estatuto - Santos', '16.0'],
                ['2 - Estatuto - Santos', '19.0'],
                [''],
                ['Trilha - Lei Orgânica - Santos', '35.0'],
            ],
        ]);

        $payload = app(CourseSpreadsheetParser::class)->parse($path);

        $this->assertSame('Secretário de Unidade Escolar', $payload['course_name']);
        $this->assertCount(2, $payload['modules']);
        $this->assertSame(70, collect($payload['modules'])->sum('workload_minutes'));
        $this->assertSame(3, collect($payload['modules'])->sum(fn (array $module) => count($module['lessons'])));
        $this->assertSame(
            'Conhecimentos Básicos de Legislação Municipal e Serviço Público - Lei Orgânica - Santos',
            $payload['modules'][1]['name'],
        );
        $this->assertSame([
            ['name' => 'Lei Orgânica - Santos', 'minutes' => 35],
        ], $payload['modules'][1]['lessons']);
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

    public function test_importer_replaces_existing_course_modules_with_spreadsheet_structure(): void
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
        $this->assertDatabaseMissing('course_modules', [
            'id' => $existingModule->id,
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
        $this->assertSame(30, $course->modules()->count());
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

    public function test_importing_structure_refreshes_existing_active_study_plans(): void
    {
        $course = Course::factory()->create([
            'name' => 'Secretário de Unidade Escolar',
            'slug' => 'secretario-de-unidade-escolar',
        ]);
        $oldModule = CourseModule::factory()->for($course)->create([
            'name' => 'Conhecimentos Básicos - Estatuto Santos',
            'type' => 'basic',
            'lessons' => [
                ['name' => '1 - Estatuto - Santos', 'minutes' => 16],
                ['name' => '2 - Estatuto - Santos', 'minutes' => 19],
            ],
            'workload_minutes' => 35,
            'sort_order' => 1,
        ]);
        $track = StudyTrack::factory()->for($course)->create([
            'name' => 'Trilha Oficial - Secretário de Unidade Escolar',
        ]);
        $track->modules()->sync([
            $oldModule->id => ['weight' => 1, 'sort_order' => 1],
        ]);
        $student = User::factory()->create();
        $student->courses()->attach($course, ['source' => 'manual']);
        $plan = $student->studyPlans()->create([
            'course_id' => $course->id,
            'study_track_id' => $track->id,
            'name' => 'Plano antigo',
            'exam_date' => now()->addWeeks(4),
            'exam_date_confirmed' => true,
            'start_date' => now(),
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'available_minutes_by_day' => [
                'monday' => 120,
                'tuesday' => 120,
                'wednesday' => 120,
                'thursday' => 120,
                'friday' => 120,
            ],
            'total_available_minutes' => 2400,
            'total_required_minutes' => 35,
            'intensity' => 'balanced',
            'status' => 'active',
            'viability_status' => 'good',
            'viability_message' => 'ok',
            'generated_at' => now(),
        ]);
        $plan->items()->create([
            'course_module_id' => $oldModule->id,
            'scheduled_date' => now()->toDateString(),
            'week_number' => 1,
            'day_of_week' => strtolower(now()->englishDayOfWeek),
            'title' => 'Bloco antigo',
            'description' => 'Bloco antigo',
            'type' => 'basic',
            'estimated_minutes' => 35,
            'sort_order' => 1,
        ]);

        app(CourseSpreadsheetImporter::class)->importInto($course, $this->makeWorkbook([
            'Nome do Curso' => [
                ['Secretário de Unidade Escolar'],
            ],
            'Conhecimentos Básicos' => [
                ['Horas aulas'],
                ['Módulo -Conhecimentos Básicos de Legislação Municipal e Serviço Público'],
                ['Trilha - Estatuto Santos'],
                ['Aula', 'Tempo de aula'],
                ['1 - Estatuto - Santos', '16.0'],
                ['2 - Estatuto - Santos', '19.0'],
                [''],
                ['Trilha - Lei Orgânica - Santos', '35.0'],
            ],
        ]));

        $plan->refresh();

        $this->assertSame(70, $plan->total_required_minutes);
        $this->assertSame(2, $track->fresh()->modules()->count());
        $this->assertTrue($plan->items()->where('estimated_minutes', 35)->where('description', 'like', '%Lei Orgânica - Santos%')->exists());
    }

    public function test_importing_structure_rebuilds_active_plans_only_from_next_week(): void
    {
        Carbon::setTestNow('2026-07-29 09:00:00');

        try {
            $course = Course::factory()->create([
                'name' => 'Curso com atualização',
                'slug' => 'curso-com-atualizacao',
            ]);
            $module = CourseModule::factory()->for($course)->create([
                'name' => 'Conhecimentos - Conteúdo A',
                'type' => 'basic',
                'lessons' => [
                    ['name' => 'Aula antiga A', 'minutes' => 30],
                ],
                'workload_minutes' => 30,
                'sort_order' => 1,
            ]);
            $track = StudyTrack::factory()->for($course)->create([
                'name' => 'Trilha Oficial - Curso com atualização',
            ]);
            $track->modules()->sync([
                $module->id => ['weight' => 1, 'sort_order' => 1],
            ]);
            $student = User::factory()->create();
            $student->courses()->attach($course, ['source' => 'manual']);
            $plan = $student->studyPlans()->create([
                'course_id' => $course->id,
                'study_track_id' => $track->id,
                'name' => 'Plano vigente',
                'exam_date' => now()->addWeeks(2),
                'exam_date_confirmed' => true,
                'start_date' => now(),
                'available_days' => ['monday', 'wednesday', 'thursday', 'friday'],
                'available_minutes_by_day' => [
                    'monday' => 120,
                    'wednesday' => 120,
                    'thursday' => 120,
                    'friday' => 120,
                ],
                'total_available_minutes' => 480,
                'total_required_minutes' => 30,
                'intensity' => 'balanced',
                'status' => 'active',
                'viability_status' => 'good',
                'viability_message' => 'ok',
                'generated_at' => now(),
            ]);
            $todayItem = $plan->items()->create([
                'course_module_id' => $module->id,
                'scheduled_date' => now()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'wednesday',
                'title' => 'Bloco de hoje',
                'description' => 'Bloco de hoje preservado.',
                'type' => 'basic',
                'estimated_minutes' => 30,
                'sort_order' => 1,
            ]);
            $currentWeekItem = $plan->items()->create([
                'course_module_id' => $module->id,
                'scheduled_date' => now()->addDay()->toDateString(),
                'week_number' => 1,
                'day_of_week' => 'thursday',
                'title' => 'Bloco da semana atual',
                'description' => 'Bloco da semana atual preservado.',
                'type' => 'basic',
                'estimated_minutes' => 30,
                'sort_order' => 2,
            ]);
            $nextWeekItem = $plan->items()->create([
                'course_module_id' => $module->id,
                'scheduled_date' => now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
                'week_number' => 2,
                'day_of_week' => 'monday',
                'title' => 'Bloco futuro antigo',
                'description' => 'Bloco futuro antigo.',
                'type' => 'basic',
                'estimated_minutes' => 30,
                'sort_order' => 3,
            ]);

            app(CourseSpreadsheetImporter::class)->importInto($course, $this->makeWorkbook([
                'Nome do Curso' => [
                    ['Curso com atualização'],
                ],
                'Conhecimentos' => [
                    ['Horas aulas'],
                    ['Módulo - Conhecimentos'],
                    ['Trilha - Conteúdo A'],
                    ['Aula', 'Tempo de aula'],
                    ['Aula atual A', '30'],
                    [''],
                    ['Trilha - Conteúdo B'],
                    ['Aula', 'Tempo de aula'],
                    ['Aula nova B', '30'],
                ],
            ]));

            $plan->refresh();

            $this->assertDatabaseHas('study_plan_items', [
                'id' => $todayItem->id,
                'title' => 'Bloco de hoje',
            ]);
            $this->assertDatabaseHas('study_plan_items', [
                'id' => $currentWeekItem->id,
                'title' => 'Bloco da semana atual',
            ]);
            $this->assertDatabaseMissing('study_plan_items', [
                'id' => $nextWeekItem->id,
            ]);
            $this->assertSame(60, $plan->total_required_minutes);
            $this->assertTrue(
                $plan->items()
                    ->whereDate('scheduled_date', '>=', now()->addWeek()->startOfWeek(CarbonInterface::MONDAY)->toDateString())
                    ->where('description', 'like', '%Aula nova B%')
                    ->exists()
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    private function makeWorkbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'course-import-').'.xlsx';
        $archive = new \ZipArchive;
        $archive->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $sheetDefinitions = [];
        $relationships = [];

        foreach (array_keys($sheets) as $index => $name) {
            $sheetNumber = $index + 1;
            $relationshipId = 'rId'.$sheetNumber;
            $sheetDefinitions[] = '<sheet name="'.e($name).'" sheetId="'.$sheetNumber.'" r:id="'.$relationshipId.'"/>';
            $relationships[] = '<Relationship Id="'.$relationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$sheetNumber.'.xml"/>';

            $archive->addFromString(
                'xl/worksheets/sheet'.$sheetNumber.'.xml',
                $this->makeWorksheet($sheets[$name]),
            );
        }

        $archive->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', $sheetDefinitions).'</sheets>'
            .'</workbook>',
        );
        $archive->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', $relationships)
            .'</Relationships>',
        );

        $archive->close();

        return $path;
    }

    private function makeWorksheet(array $rows): string
    {
        $rowXml = collect($rows)
            ->map(function (array $row, int $rowIndex) {
                $cells = collect($row)
                    ->map(function (?string $value, int $columnIndex) use ($rowIndex) {
                        $column = chr(65 + $columnIndex);
                        $reference = $column.($rowIndex + 1);

                        return '<c r="'.$reference.'" t="inlineStr"><is><t>'.e((string) $value).'</t></is></c>';
                    })
                    ->implode('');

                return '<row r="'.($rowIndex + 1).'">'.$cells.'</row>';
            })
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rowXml.'</sheetData>'
            .'</worksheet>';
    }
}
