<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SantosComboCourseLinkCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_students_from_santos_nivel_medio_combo_to_active_gabaritando_santos_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $subscriber = User::factory()->create(['role' => 'subscriber']);

        $comboPlaceholder = Course::factory()->create([
            'name' => 'GABARITANDO SANTOS - COMBO NÍVEL MÉDIO',
            'is_active' => true,
        ]);
        $targetCourseA = Course::factory()->create([
            'name' => 'Gabaritando Santos - Matemática',
            'is_active' => true,
        ]);
        $targetCourseB = Course::factory()->create([
            'name' => 'Gabaritando Santos - Português',
            'is_active' => true,
        ]);
        $inactiveTarget = Course::factory()->create([
            'name' => 'Gabaritando Santos - Curso Inativo',
            'is_active' => false,
        ]);
        $otherCourse = Course::factory()->create([
            'name' => 'Gabaritando São Vicente - Matemática',
            'is_active' => true,
        ]);

        $student->courses()->attach($comboPlaceholder, ['source' => 'tutory']);
        $subscriber->courses()->attach($comboPlaceholder, ['source' => 'tutory']);
        $otherStudent->courses()->attach($otherCourse, ['source' => 'manual']);

        $this->artisan('courses:link-santos-nivel-medio')
            ->expectsOutput('1 aluno(s) do combo GABARITANDO SANTOS - COMBO NÍVEL MÉDIO.')
            ->expectsOutput('2 curso(s) ativo(s) com prefixo Gabaritando Santos.')
            ->expectsOutput('2 vínculo(s) criados.')
            ->assertExitCode(0);

        $this->assertTrue($student->fresh()->courses()->whereKey($targetCourseA->id)->exists());
        $this->assertTrue($student->fresh()->courses()->whereKey($targetCourseB->id)->exists());
        $this->assertFalse($student->fresh()->courses()->whereKey($inactiveTarget->id)->exists());
        $this->assertFalse($student->fresh()->courses()->whereKey($otherCourse->id)->exists());
        $this->assertFalse($subscriber->fresh()->courses()->whereKey($targetCourseA->id)->exists());
        $this->assertFalse($otherStudent->fresh()->courses()->whereKey($targetCourseA->id)->exists());
    }

    public function test_dry_run_reports_links_without_persisting_them(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $comboPlaceholder = Course::factory()->create([
            'name' => 'GABARITANDO SANTOS - COMBO NÍVEL MÉDIO',
        ]);
        $targetCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Conhecimentos Gerais',
            'is_active' => true,
        ]);

        $student->courses()->attach($comboPlaceholder, ['source' => 'tutory']);

        $this->artisan('courses:link-santos-nivel-medio --dry-run')
            ->expectsOutput('1 vínculo(s) simulados.')
            ->assertExitCode(0);

        $this->assertFalse($student->fresh()->courses()->whereKey($targetCourse->id)->exists());
    }
}
