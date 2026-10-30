<?php

namespace Tests\Feature;

use App\Models\AiArtifact;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LessonDuplicateMergerTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_merges_lessons_with_same_name_and_preserves_relationships(): void
    {
        $course = Course::factory()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'name' => 'Português']);
        $otherModule = CourseModule::factory()->create(['course_id' => $course->id, 'name' => 'Português']);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Ortografia',
            'slug' => 'ortografia',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $otherTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $otherModule->id,
            'name' => 'Ortografia',
            'slug' => 'ortografia',
            'sort_order' => 1,
            'status' => 'published',
        ]);

        $placeholder = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => 'Acentuação gráfica',
            'slug' => 'acentuacao-grafica',
            'status' => 'draft',
            'duration_seconds' => 0,
            'source_status' => 'structure_only',
            'description' => null,
        ]);
        $canonical = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => $otherModule->id,
            'course_module_track_id' => $otherTrack->id,
            'title' => 'Acentuação gráfica',
            'slug' => 'acentuacao-grafica',
            'status' => 'published',
            'duration_seconds' => 1800,
            'panda_video_id' => 'panda-001',
            'panda_embed_url' => 'https://player.example.test/panda-001',
            'digital_book_path' => 'books/acentuacao.pdf',
            'source_status' => 'media_ready',
        ]);

        $module->onlineLessons()->syncWithoutDetaching([$placeholder->id => ['sort_order' => 2]]);
        $track->lessons()->syncWithoutDetaching([$placeholder->id => ['sort_order' => 2]]);
        $track->courseLessons()->syncWithoutDetaching([
            $placeholder->id => [
                'course_id' => $course->id,
                'sort_order' => 2,
                'status' => 'published',
                'metadata' => json_encode(['source' => 'test']),
            ],
        ]);

        $user = User::factory()->create();
        $plan = StudyPlan::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
        ]);
        $item->lessons()->syncWithoutDetaching([$placeholder->id => ['sort_order' => 1]]);

        LessonProgress::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'lesson_id' => $canonical->id,
            'status' => 'in_progress',
            'progress_seconds' => 120,
        ]);
        LessonProgress::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'lesson_id' => $placeholder->id,
            'status' => 'completed',
            'progress_seconds' => 600,
            'completed_at' => now(),
        ]);

        DB::table('lesson_comments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'lesson_id' => $placeholder->id,
            'body' => 'Dúvida da aula duplicada',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bankId = DB::table('question_banks')->insertGetId([
            'title' => 'Banco Português',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('questions')->insert([
            'question_bank_id' => $bankId,
            'lesson_id' => $placeholder->id,
            'number' => 1,
            'statement' => 'Questão vinculada à aula duplicada',
            'type' => 'multiple_choice',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('question_bank_lesson')->insert([
            'question_bank_id' => $bankId,
            'lesson_id' => $placeholder->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $placeholder->id,
            'artifact_type' => 'summary',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => ['text' => 'Resumo da duplicada'],
        ]);

        $this->artisan('lessons:merge-duplicates')
            ->expectsOutputToContain('Grupos duplicados encontrados: 1')
            ->expectsOutputToContain('Simulação concluída')
            ->assertExitCode(0);

        $this->assertDatabaseHas('lessons', ['id' => $placeholder->id]);

        $this->artisan('lessons:merge-duplicates --apply')
            ->expectsOutputToContain('Unificação concluída')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('lessons', ['id' => $placeholder->id]);
        $this->assertDatabaseHas('lessons', [
            'id' => $canonical->id,
            'panda_video_id' => 'panda-001',
            'digital_book_path' => 'books/acentuacao.pdf',
        ]);
        $this->assertDatabaseHas('course_module_lessons', [
            'course_module_id' => $module->id,
            'lesson_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('course_module_track_lessons', [
            'course_module_track_id' => $track->id,
            'lesson_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('course_module_track_lesson_course', [
            'course_id' => $course->id,
            'course_module_track_id' => $track->id,
            'lesson_id' => $canonical->id,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('study_plan_item_lessons', [
            'study_plan_item_id' => $item->id,
            'lesson_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $user->id,
            'lesson_id' => $canonical->id,
            'status' => 'completed',
            'progress_seconds' => 600,
        ]);
        $this->assertDatabaseHas('lesson_comments', [
            'lesson_id' => $canonical->id,
            'body' => 'Dúvida da aula duplicada',
        ]);
        $this->assertDatabaseHas('questions', [
            'lesson_id' => $canonical->id,
            'statement' => 'Questão vinculada à aula duplicada',
        ]);
        $this->assertDatabaseHas('question_bank_lesson', [
            'question_bank_id' => $bankId,
            'lesson_id' => $canonical->id,
        ]);
        $this->assertDatabaseHas('ai_artifacts', [
            'source_type' => Lesson::class,
            'source_id' => $canonical->id,
            'artifact_type' => 'summary',
        ]);

        $metadata = Lesson::query()->findOrFail($canonical->id)->metadata;
        $this->assertContains($placeholder->id, $metadata['merged_duplicate_lesson_ids']);
        $this->assertSame('acentuacao-grafica', Str::slug($canonical->title));
    }
}
