<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Services\CourseLessonMediaImporter;
use App\Services\LessonMediaMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseLessonMediaImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_matcher_finds_media_by_approximate_lesson_name(): void
    {
        $matched = app(LessonMediaMatcher::class)->matchLessons([
            ['name' => 'Classes de palavras - Conjunção subordinativa adverbial', 'minutes' => 16],
        ], [
            ['id' => 'drive-1', 'name' => 'Aula 03 - Classes de Palavras Conjuncao Subordinativa Adverbial.mp4'],
        ]);

        $this->assertSame('imported', $matched[0]['media_status']);
        $this->assertSame('drive-1', $matched[0]['media_file_id']);
        $this->assertGreaterThanOrEqual(0.72, $matched[0]['media_match_confidence']);
    }

    public function test_importer_marks_module_lessons_with_imported_or_missing_media(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->for($course)->create([
            'lessons' => [
                ['name' => 'Administração Pública - Parte 01', 'minutes' => 21],
                ['name' => 'Administração Pública - Parte 02', 'minutes' => 21],
                ['name' => 'Controle Interno', 'minutes' => 20],
            ],
            'workload_minutes' => 62,
        ]);
        $manifest = tempnam(sys_get_temp_dir(), 'media-manifest-');

        file_put_contents($manifest, json_encode([
            'files' => [
                ['id' => 'file-1', 'name' => 'Aula 01 Administracao Publica Parte 01.mp4', 'mime_type' => 'video/mp4'],
                ['id' => 'file-2', 'name' => 'Aula 02 Administracao Publica Parte 02.mp4', 'mime_type' => 'video/mp4'],
            ],
        ], JSON_THROW_ON_ERROR));

        $summary = app(CourseLessonMediaImporter::class)->importFromManifest($course, $manifest);

        $module->refresh();

        $this->assertSame([
            'modules' => 1,
            'lessons' => 3,
            'imported' => 2,
            'missing' => 1,
        ], $summary);
        $this->assertSame(2, $module->imported_media_lessons_count);
        $this->assertSame(1, $module->missing_media_lessons_count);
        $this->assertSame('2/3', $module->media_coverage_label);
        $this->assertSame('Controle Interno', $module->missing_media_lessons_label);
        $this->assertSame(2, $module->published_lessons_count);
        $this->assertTrue($module->lessons[0]['has_media']);
        $this->assertTrue($module->lessons[0]['is_published']);
        $this->assertSame('imported', $module->lessons[0]['media_status']);
        $this->assertFalse($module->lessons[2]['has_media']);
        $this->assertFalse($module->lessons[2]['is_published']);
        $this->assertSame('missing', $module->lessons[2]['media_status']);
    }

    public function test_module_preserves_media_metadata_when_lessons_are_edited_by_textarea(): void
    {
        $module = CourseModule::factory()->create([
            'lessons' => [
                [
                    'name' => 'Administração Pública - Parte 01',
                    'minutes' => 21,
                    'media_status' => 'imported',
                    'has_media' => true,
                    'is_published' => true,
                    'media_file_id' => 'file-1',
                    'media_name' => 'Aula 01 Administracao Publica Parte 01.mp4',
                ],
            ],
        ]);

        $module->update([
            'lessons' => [
                ['name' => 'Administração Pública - Parte 01', 'minutes' => 25],
            ],
        ]);

        $module->refresh();

        $this->assertSame(25, $module->lessons[0]['minutes']);
        $this->assertSame('imported', $module->lessons[0]['media_status']);
        $this->assertTrue($module->lessons[0]['has_media']);
        $this->assertTrue($module->lessons[0]['is_published']);
        $this->assertSame('file-1', $module->lessons[0]['media_file_id']);
    }
}
