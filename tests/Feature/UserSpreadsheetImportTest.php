<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Services\UserSpreadsheetImporter;
use App\Services\UserSpreadsheetParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserSpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_keeps_only_active_students(): void
    {
        $payload = app(UserSpreadsheetParser::class)->parse(
            base_path('tests/Fixtures/Imports/Alunos.xlsx')
        );

        $this->assertSame(5, $payload['total_rows']);
        $this->assertSame(3, $payload['active_rows']);
        $this->assertSame(2, $payload['skipped_inactive_rows']);
        $this->assertSame(0, $payload['invalid_rows']);
        $this->assertCount(2, $payload['students']);
        $this->assertNotContains('encerrado@example.com', array_column($payload['students'], 'email'));
    }

    public function test_importer_creates_active_students_and_links_existing_courses(): void
    {
        Notification::fake();

        $course = Course::factory()->create([
            'name' => 'Gabaritando Santos',
            'tutory_product_id' => 'c5567302-8fdb-4434-ac7a-99d8c977f39a',
        ]);

        $comboCourse = Course::factory()->create([
            'name' => 'Gabaritando Santos - Oficial de Administração',
            'tutory_product_id' => null,
            'combo_name' => 'Combo Extra, Gabaritando Prefeitura de Santos',
        ]);

        $stats = app(UserSpreadsheetImporter::class)->import(
            base_path('tests/Fixtures/Imports/Alunos.xlsx'),
            sendFirstAccessEmail: true,
        );

        $this->assertSame(2, $stats['created']);
        $this->assertSame(0, $stats['updated']);
        $this->assertGreaterThan(0, $stats['linked_courses']);
        $this->assertSame(2, $stats['emails_sent']);

        $this->assertDatabaseMissing('users', [
            'email' => 'encerrado@example.com',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'deby@example.com',
            'name' => 'Débora',
            'role' => 'student',
        ]);

        $student = User::query()->where('email', 'renee@example.com')->first();

        $this->assertNotNull($student);
        $this->assertTrue($student->courses()->whereKey($course->id)->exists());

        $comboStudent = User::query()->where('email', 'deby@example.com')->first();

        $this->assertNotNull($comboStudent);
        $this->assertTrue($comboStudent->courses()->whereKey($comboCourse->id)->exists());

        Notification::assertSentTo($student, SetPasswordNotification::class);
    }

    public function test_importer_updates_existing_students_without_overwriting_role(): void
    {
        User::factory()->create([
            'email' => 'deby@example.com',
            'name' => 'Nome Antigo',
            'role' => 'subscriber',
        ]);

        $stats = app(UserSpreadsheetImporter::class)->import(
            base_path('tests/Fixtures/Imports/Alunos.xlsx')
        );

        $this->assertSame(1, $stats['created']);
        $this->assertSame(1, $stats['updated']);

        $this->assertDatabaseHas('users', [
            'email' => 'deby@example.com',
            'name' => 'Débora',
            'role' => 'subscriber',
        ]);
    }
}
