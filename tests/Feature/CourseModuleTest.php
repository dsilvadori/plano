<?php

namespace Tests\Feature;

use App\Models\CourseModule;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_same_named_module_inherits_teacher_from_existing_module(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Professora Ana Silva']);

        CourseModule::factory()->create([
            'name' => 'Português',
            'teacher_id' => $teacher->id,
            'teacher_name' => 'Professora Ana Silva',
        ]);

        $newModule = CourseModule::factory()->create([
            'name' => '  PORTUGUES  ',
            'teacher_id' => null,
            'teacher_name' => null,
        ]);

        $this->assertSame($teacher->id, $newModule->teacher_id);
        $this->assertSame('Professora Ana Silva', $newModule->teacher_name);
    }

    public function test_new_same_named_module_keeps_explicit_teacher(): void
    {
        $sourceTeacher = Teacher::factory()->create(['name' => 'Professor Original']);
        $explicitTeacher = Teacher::factory()->create(['name' => 'Professor Informado']);

        CourseModule::factory()->create([
            'name' => 'Matemática',
            'teacher_id' => $sourceTeacher->id,
            'teacher_name' => 'Professor Original',
        ]);

        $newModule = CourseModule::factory()->create([
            'name' => 'Matemática',
            'teacher_id' => $explicitTeacher->id,
            'teacher_name' => 'Professor Informado',
        ]);

        $this->assertSame($explicitTeacher->id, $newModule->teacher_id);
        $this->assertSame('Professor Informado', $newModule->teacher_name);
    }
}
