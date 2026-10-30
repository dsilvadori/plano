<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Notifications\CourseAccessGrantedNotification;
use App\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserEmailUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_email_is_normalized_before_saving(): void
    {
        $user = User::factory()->create([
            'email' => '  ALUNO@Example.COM  ',
        ]);

        $this->assertSame('aluno@example.com', $user->email);
        $this->assertDatabaseHas('users', [
            'email' => 'aluno@example.com',
        ]);
    }

    public function test_first_or_new_by_email_finds_existing_user_case_insensitively(): void
    {
        DB::table('users')->insert([
            'name' => 'Aluno Existente',
            'email' => 'Aluno@Example.COM',
            'password' => 'password',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::firstOrNewByEmail(' aluno@example.com ');

        $this->assertTrue($user->exists);
        $this->assertSame(1, User::count());
    }

    public function test_webhook_reuses_existing_user_when_email_case_differs(): void
    {
        Notification::fake();

        DB::table('users')->insert([
            'name' => 'João da Silva',
            'email' => 'JOAO@EMAIL.COM',
            'password' => 'password',
            'role' => 'student',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $course = Course::factory()->create([
            'name' => 'Gabaritando Santos - Inspetor de Alunos',
            'tutory_product_id' => 'gabaritando-santos-inspetor-de-alunos',
            'is_active' => true,
        ]);

        $this->postJson('/webhooks/tutory', [
            'event_id' => 'evt_same_email_case',
            'event_type' => 'purchase.approved',
            'purchase' => [
                'id' => 'purchase_same_email_case',
                'status' => 'approved',
                'product_id' => 'gabaritando-santos-inspetor-de-alunos',
                'product_name' => 'Gabaritando Santos - Inspetor de Alunos',
                'student' => [
                    'name' => 'João da Silva',
                    'email' => 'joao@email.com',
                    'phone' => '11999999999',
                ],
            ],
        ], [
            'Authorization' => 'Bearer secret-local',
        ])->assertOk();

        $user = User::firstOrFail();

        $this->assertSame(1, User::count());
        $this->assertTrue($user->courses()->whereKey($course)->exists());
        Notification::assertSentTo($user, CourseAccessGrantedNotification::class);
        Notification::assertNotSentTo($user, SetPasswordNotification::class);
    }
}
