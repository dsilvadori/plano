<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_admin_panel(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_student_cannot_access_admin_panel(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_can_access_courses_page_and_see_import_action(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/courses')
            ->assertOk()
            ->assertSee('Importar planilha');
    }

    public function test_admin_can_access_student_dashboard_and_see_all_active_courses(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@vencendoconcursos.com.br',
        ]);

        $activeCourse = Course::factory()->create([
            'name' => 'Curso Ativo',
            'is_active' => true,
        ]);

        Course::factory()->create([
            'name' => 'Curso Inativo',
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Área de estudos do admin')
            ->assertSee($activeCourse->name)
            ->assertDontSee('Curso Inativo');
    }

    public function test_admin_can_access_study_plan_builder(): void
    {
        $admin = User::factory()->admin()->create();

        Course::factory()->create([
            'name' => 'Curso Admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('study-plans.create'))
            ->assertOk()
            ->assertSee('Criador de plano')
            ->assertSee('Curso Admin');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
