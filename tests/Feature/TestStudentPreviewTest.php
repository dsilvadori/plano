<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestStudentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enter_test_student_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create([
            'email' => 'aluno@teste.com',
            'role' => 'student',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.preview-test-student.enter'));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($student);
        $this->assertSame($admin->id, session('admin_preview_user_id'));
    }

    public function test_preview_user_can_return_to_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $student = User::factory()->create([
            'email' => 'aluno@teste.com',
            'role' => 'student',
        ]);

        $response = $this->actingAs($student)
            ->withSession(['admin_preview_user_id' => $admin->id])
            ->get(route('admin.preview-test-student.exit'));

        $response->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session('admin_preview_user_id'));
    }

    public function test_non_preview_student_cannot_return_to_admin(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get(route('admin.preview-test-student.exit'))
            ->assertForbidden();
    }
}
