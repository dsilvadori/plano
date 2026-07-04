<?php

namespace Tests\Feature;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use App\Models\AiArtifact;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Services\PandaVideoClient;
use App\Services\StudyPlanGenerator;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CourseCatalogFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_course_catalog_relations_are_available(): void
    {
        $sphere = CourseSphere::factory()->create(['name' => 'Federal']);
        $level = EducationLevel::factory()->create(['name' => 'Ensino Médio']);

        $course = Course::factory()->create([
            'sphere_id' => $sphere->id,
            'education_level_id' => $level->id,
            'status' => 'published',
            'is_featured' => true,
        ]);

        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'panda_folder_id' => 'folder_123',
        ]);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'panda_video_id' => 'video_123',
            'duration_seconds' => 125,
        ]);

        $this->assertTrue($course->fresh()->sphere->is($sphere));
        $this->assertTrue($course->fresh()->educationLevel->is($level));
        $this->assertTrue($course->fresh()->lessons->contains($lesson));
        $this->assertTrue($module->fresh()->onlineLessons->contains($lesson));
        $this->assertSame(3, $lesson->duration_minutes);
    }

    public function test_student_can_browse_catalog_and_only_sees_enrolled_courses_in_my_courses(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $enrolledCourse = Course::factory()->create([
            'name' => 'Curso liberado',
            'status' => 'published',
            'is_featured' => true,
        ]);
        $lockedCourse = Course::factory()->create([
            'name' => 'Curso bloqueado',
            'status' => 'published',
            'checkout_url' => 'https://checkout.example.com/curso',
        ]);

        $student->courses()->attach($enrolledCourse, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.index'))
            ->assertOk()
            ->assertSee('Curso liberado')
            ->assertSee('Curso bloqueado')
            ->assertSee('Comprar acesso');

        $this->actingAs($student)
            ->get(route('courses.mine'))
            ->assertOk()
            ->assertSee('Curso liberado')
            ->assertDontSee('Curso bloqueado');

        $this->actingAs($student)
            ->get(route('courses.show', $lockedCourse->slug))
            ->assertOk()
            ->assertSee('Acesso bloqueado')
            ->assertSee('Comprar acesso');
    }

    public function test_course_catalog_uses_uploaded_thumbnail_before_external_url(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create([
            'name' => 'Curso com thumbnail enviada',
            'status' => 'published',
            'is_featured' => true,
            'thumbnail_url' => 'https://example.com/external-thumbnail.jpg',
            'thumbnail_path' => 'course-thumbnails/curso-upload.webp',
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.index'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('course-thumbnails/curso-upload.webp'), false)
            ->assertDontSee('https://example.com/external-thumbnail.jpg', false);
    }

    public function test_enrolled_student_can_watch_and_complete_lesson(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create([
            'name' => 'Curso com player',
            'status' => 'published',
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Módulo inicial',
            'sort_order' => 1,
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Aula com Panda',
            'panda_embed_url' => 'https://player.example.com/embed/video-123',
            'duration_seconds' => 900,
            'status' => 'published',
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Aula com Panda')
            ->assertSee('https://player.example.com/embed/video-123', false);

        $this->assertDatabaseHas('lesson_progress', [
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'status' => 'in_progress',
        ]);

        $this->actingAs($student)
            ->post(route('courses.lessons.complete', [$course->slug, $lesson]))
            ->assertRedirect();

        $progress = LessonProgress::query()
            ->where('user_id', $student->id)
            ->where('lesson_id', $lesson->id)
            ->firstOrFail();

        $this->assertSame('completed', $progress->status);
        $this->assertSame(900, $progress->progress_seconds);
        $this->assertNotNull($progress->completed_at);

        $this->actingAs($student)
            ->get(route('courses.show', $course->slug))
            ->assertOk()
            ->assertSee('1 de 1 aula(s) concluída(s).')
            ->assertSee('Rever trilha')
            ->assertSee(route('courses.lessons.show', [$course->slug, $lesson]), false);
    }

    public function test_course_track_card_links_to_in_progress_lesson_before_first_lesson(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create([
            'name' => 'Curso com progresso por trilha',
            'status' => 'published',
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Informática',
            'sort_order' => 1,
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $track->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);

        $firstLesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Introdução',
            'status' => 'published',
        ]);
        $secondLesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '02 - Configurações',
            'status' => 'published',
        ]);

        $module->courses()->syncWithoutDetaching([
            $course->id => ['sort_order' => 1],
        ]);
        $module->onlineLessons()->syncWithoutDetaching([
            $firstLesson->id => ['sort_order' => 1],
            $secondLesson->id => ['sort_order' => 2],
        ]);
        $track->lessons()->syncWithoutDetaching([
            $firstLesson->id => ['sort_order' => 1],
            $secondLesson->id => ['sort_order' => 2],
        ]);
        $student->courses()->attach($course, ['source' => 'manual']);

        LessonProgress::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $secondLesson->id,
            'status' => 'in_progress',
            'progress_seconds' => 120,
        ]);

        $this->actingAs($student)
            ->get(route('courses.show', $course->slug))
            ->assertOk()
            ->assertSee('Continuar trilha')
            ->assertSee('02 - Configurações')
            ->assertSee(route('courses.lessons.show', [$course->slug, $secondLesson]), false)
            ->assertDontSee('Assistir aula');
    }

    public function test_courses_only_show_tracks_explicitly_linked_to_them(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $firstCourse = Course::factory()->create([
            'name' => 'Curso com trilha A',
            'status' => 'published',
        ]);
        $secondCourse = Course::factory()->create([
            'name' => 'Curso com trilha B',
            'status' => 'published',
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => $firstCourse->id,
            'name' => 'Português',
            'sort_order' => 1,
        ]);
        $module->courses()->syncWithoutDetaching([
            $secondCourse->id => ['sort_order' => 1],
        ]);

        $firstTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Classe de palavras',
            'slug' => 'classe-de-palavras',
            'status' => 'published',
            'sort_order' => 1,
        ]);
        $secondTrack = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Crase',
            'slug' => 'crase',
            'status' => 'published',
            'sort_order' => 2,
        ]);
        $firstTrack->courses()->attach($firstCourse->id, ['sort_order' => 1]);
        $secondTrack->courses()->attach($secondCourse->id, ['sort_order' => 1]);

        $firstLesson = Lesson::factory()->create([
            'course_id' => $firstCourse->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $firstTrack->id,
            'title' => 'Aula da primeira trilha',
            'status' => 'published',
        ]);
        $secondLesson = Lesson::factory()->create([
            'course_id' => $firstCourse->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $secondTrack->id,
            'title' => 'Aula da segunda trilha',
            'status' => 'published',
        ]);

        $student->courses()->attach($firstCourse, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.show', $firstCourse->slug))
            ->assertOk()
            ->assertSee('Classe de palavras')
            ->assertSee('Aula da primeira trilha')
            ->assertDontSee('Crase')
            ->assertDontSee('Aula da segunda trilha');

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$firstCourse->slug, $firstLesson]))
            ->assertOk();

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$firstCourse->slug, $secondLesson]))
            ->assertNotFound();
    }

    public function test_deleting_course_module_or_track_preserves_lessons(): void
    {
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => 'Windows 10',
            'slug' => 'windows-10',
            'status' => 'published',
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'course_module_track_id' => $track->id,
            'title' => '01 - Windows 10',
            'status' => 'published',
        ]);

        $module->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);
        $module->onlineLessons()->syncWithoutDetaching([$lesson->id => ['sort_order' => 1]]);
        $track->courses()->syncWithoutDetaching([$course->id => ['sort_order' => 1]]);
        $track->lessons()->syncWithoutDetaching([$lesson->id => ['sort_order' => 1]]);

        $track->delete();

        $lesson = $lesson->fresh();

        $this->assertNotNull($lesson);
        $this->assertNull($lesson->course_module_track_id);
        $this->assertDatabaseMissing('course_module_track_lessons', [
            'lesson_id' => $lesson->id,
            'course_module_track_id' => $track->id,
        ]);

        $module->delete();

        $lesson = $lesson->fresh();

        $this->assertNotNull($lesson);
        $this->assertNull($lesson->course_module_id);
        $this->assertNull($lesson->course_module_track_id);
        $this->assertSame($course->id, $lesson->course_id);
        $this->assertDatabaseMissing('course_module_lessons', [
            'lesson_id' => $lesson->id,
            'course_module_id' => $module->id,
        ]);

        $course->delete();

        $lesson = $lesson->fresh();

        $this->assertNotNull($lesson);
        $this->assertNull($lesson->course_id);
    }

    public function test_locked_student_cannot_open_lesson_player(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'status' => 'published',
        ]);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertForbidden();
    }

    public function test_generated_study_plan_links_real_online_lessons(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
            'workload_minutes' => 30,
            'sort_order' => 1,
            'lessons' => [
                ['name' => 'Classes de palavras', 'minutes' => 30],
            ],
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'duration_seconds' => 1800,
            'status' => 'published',
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $startDate = now()->next(CarbonInterface::MONDAY)->toDateString();
        $plan = app(StudyPlanGenerator::class)->generate(
            $student,
            $course,
            null,
            now()->next(CarbonInterface::MONDAY)->addWeek()->toDateString(),
            $startDate,
            ['monday'],
            ['monday' => 60],
            'balanced',
        );

        $item = $plan->items()->where('course_module_id', $module->id)->firstOrFail();

        $this->assertTrue($item->lessons()->whereKey($lesson->id)->exists());

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Classes de palavras')
            ->assertSee(route('courses.lessons.show', [$course->slug, $lesson]), false);
    }

    public function test_completing_linked_lesson_marks_plan_item_when_all_lessons_are_done(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'status' => 'published',
            'duration_seconds' => 600,
        ]);
        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'completed_at' => null,
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);
        $item->lessons()->attach($lesson, ['sort_order' => 1]);

        $this->actingAs($student)
            ->post(route('courses.lessons.complete', [$course->slug, $lesson]))
            ->assertRedirect();

        $this->assertNotNull($item->fresh()->completed_at);
    }

    public function test_lesson_page_shows_active_plan_day_track_sidebar(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create([
            'name' => 'Curso com plano',
            'status' => 'published',
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Arquivologia',
            'type' => 'specific',
        ]);
        $firstLesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => '01 - Conceitos Iniciais',
            'duration_seconds' => 1200,
            'status' => 'published',
        ]);
        $secondLesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => '02 - Terminologias',
            'duration_seconds' => 900,
            'status' => 'published',
        ]);
        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'status' => 'active',
        ]);
        $item = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'scheduled_date' => '2026-06-29',
            'title' => 'Bloco 1: Arquivologia',
            'estimated_minutes' => 35,
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);
        $item->lessons()->attach($secondLesson, ['sort_order' => 1]);
        $item->lessons()->attach($firstLesson, ['sort_order' => 2]);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $firstLesson]))
            ->assertOk()
            ->assertSee('Trilha do plano')
            ->assertSee('Voltar ao plano')
            ->assertSee(route('study-plans.show', $plan), false)
            ->assertSee('01 - Conceitos Iniciais')
            ->assertSee('02 - Terminologias')
            ->assertSeeInOrder(['01 - Conceitos Iniciais', '02 - Terminologias']);
    }

    public function test_lesson_page_renders_interactive_ai_questions(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'status' => 'published',
        ]);

        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'summary',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => [
                'text' => "## Resumo da aula\n\n**Substantivo** e a classe que nomeia seres.",
            ],
        ]);
        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'quiz',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => [
                'questions' => [[
                    'enunciate' => 'Sobre classes de palavras, marque a alternativa correta.',
                    'options' => [
                        ['text' => 'Substantivo nomeia seres.', 'correct' => true],
                        ['text' => 'Adjetivo sempre indica ação.', 'correct' => false],
                    ],
                    'explanation' => 'Substantivo e a classe que nomeia seres.',
                ]],
            ],
        ]);
        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'mindmap',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => [
                'theme' => 'Classes de palavras',
                'children' => [[
                    'text' => 'Substantivo',
                    'time' => '00:02:48.075',
                    'children' => [[
                        'text' => 'Nomeia seres',
                        'time' => '00:03:33.390',
                    ]],
                ]],
            ],
        ]);

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Sobre classes de palavras, marque a alternativa correta.')
            ->assertSee('Baixar resumo em PDF')
            ->assertSee(route('courses.lessons.summary.pdf', [$course->slug, $lesson]), false)
            ->assertSee('activeTab: null', false)
            ->assertSee('@click="selected = 0; revealed = true"', false)
            ->assertSee('Gabarito: Substantivo nomeia seres.')
            ->assertSee('<strong>Substantivo</strong>', false)
            ->assertDontSee('**Substantivo**')
            ->assertDontSee('Momentos da aula')
            ->assertDontSee('lesson-summary-inline-time', false)
            ->assertSee('tutorTabVisible: false', false)
            ->assertDontSee('Sincronizar IA da aula');

        $pdfResponse = $this->actingAs($student)
            ->get(route('courses.lessons.summary.pdf', [$course->slug, $lesson]));

        $pdfResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $pdfResponse->getContent());
    }

    public function test_cached_lesson_ai_artifacts_skip_provider_sync(): void
    {
        config([
            'services.panda.ai_auto_sync' => true,
            'services.panda.api_key' => 'testing-key',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'panda_video_id' => 'video-123',
            'status' => 'published',
        ]);

        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'summary',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => ['text' => 'Resumo em cache compartilhado.'],
        ]);
        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'quiz',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => [
                'questions' => [
                    ['question' => 'Pergunta em cache?', 'answer' => true],
                ],
            ],
        ]);
        AiArtifact::query()->create([
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'artifact_type' => 'mindmap',
            'provider' => 'panda',
            'status' => 'ready',
            'content' => [
                'theme' => 'Mapa em cache',
                'children' => [['text' => 'Topico em cache']],
            ],
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldNotReceive('createAiPackage');
            $mock->shouldNotReceive('aiPackage');
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Resumo em cache compartilhado.')
            ->assertSee('Gerando resumo da aula...');
    }

    public function test_lesson_page_auto_syncs_missing_ai_artifacts_by_default(): void
    {
        config([
            'services.panda.ai_auto_sync' => true,
            'services.panda.api_key' => 'testing-key',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'panda_video_id' => 'video-123',
            'panda_embed_url' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'video_external_id' => 'external-123',
                    'video_player' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-123',
                ],
            ],
            'status' => 'published',
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldNotReceive('createAiPackage');

            $mock->shouldReceive('aiPackage')
                ->once()
                ->with('vz-abc12345-abc', 'external-123')
                ->andReturn([
                    'summary' => 'Resumo compartilhado da aula.',
                    'questions' => [
                        ['question' => 'Classes de palavras nomeiam seres?', 'answer' => true],
                    ],
                    'mindmap' => [
                        'theme' => 'Classes de palavras',
                        'children' => [['text' => 'Substantivo']],
                    ],
                ]);
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Resumo compartilhado da aula.')
            ->assertSee('Classes de palavras nomeiam seres?')
            ->assertSee('Substantivo');

        foreach (['summary', 'quiz', 'mindmap'] as $type) {
            $this->assertDatabaseHas('ai_artifacts', [
                'source_type' => Lesson::class,
                'source_id' => $lesson->id,
                'artifact_type' => $type,
                'provider' => 'panda',
                'status' => 'ready',
            ]);
        }
    }

    public function test_lesson_page_requests_panda_ai_only_when_published_package_is_not_ready(): void
    {
        config([
            'services.panda.ai_auto_sync' => true,
            'services.panda.api_key' => 'testing-key',
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Administração geral',
            'panda_video_id' => 'video-456',
            'panda_embed_url' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
            'metadata' => [
                'payload' => [
                    'video_external_id' => 'external-456',
                    'video_player' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
                ],
            ],
            'status' => 'published',
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldReceive('aiPackage')
                ->once()
                ->with('vz-abc12345-abc', 'external-456')
                ->andReturn(null);

            $mock->shouldReceive('createAiPackage')
                ->once()
                ->with('video-456')
                ->andReturn(['status' => 'requested']);
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertDontSee('Assistente da aula')
            ->assertDontSee('Estamos preparando o resumo desta aula.');

        $lesson->refresh();

        $this->assertNotNull(data_get($lesson->metadata, 'panda_ai.requested_at'));
        $this->assertSame('regenerating', data_get($lesson->metadata, 'panda_ai.last_payload_status'));
        $this->assertSame(1, data_get($lesson->metadata, 'panda_ai.request_count'));
    }

    public function test_lesson_page_does_not_reimport_stale_panda_ai_package_right_after_regeneration_request(): void
    {
        config([
            'services.panda.ai_auto_sync' => true,
            'services.panda.api_key' => 'testing-key',
            'services.panda.ai_regeneration_poll_delay_minutes' => 10,
            'services.panda.tutor_auto_detect' => false,
        ]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Administração geral',
            'panda_video_id' => 'video-456',
            'panda_embed_url' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
            'metadata' => [
                'payload' => [
                    'video_external_id' => 'external-456',
                    'video_player' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
                ],
                'panda_ai' => [
                    'requested_at' => now()->toIso8601String(),
                    'last_request_status' => 'requested',
                    'last_payload_status' => 'regenerating',
                ],
            ],
            'status' => 'published',
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldNotReceive('aiPackage');
            $mock->shouldNotReceive('createAiPackage');
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertDontSee('Assistente da aula')
            ->assertDontSee('Estamos preparando o resumo desta aula.');

        $this->assertDatabaseMissing('ai_artifacts', [
            'source_type' => Lesson::class,
            'source_id' => $lesson->id,
            'provider' => 'panda',
        ]);
    }

    public function test_lesson_page_embeds_panda_tutor_when_video_metadata_is_available(): void
    {
        config(['services.panda.tutor_auto_detect' => true]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras',
            'panda_video_id' => 'video-123',
            'panda_embed_url' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-123',
            'metadata' => [
                'payload' => [
                    'video_external_id' => 'external-123',
                    'video_player' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-123',
                ],
            ],
            'status' => 'published',
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldReceive('playerConfig')
                ->once()
                ->with('vz-abc12345-abc', 'external-123')
                ->andReturn(['assistant_id' => 'assistant-123']);
            $mock->shouldReceive('tutorAssistant')
                ->once()
                ->with('assistant-123')
                ->andReturn([
                    'id' => 'assistant-123',
                    'status' => 'ready',
                    'videos' => [
                        ['id' => 'video-123', 'video_external_id' => 'external-123'],
                    ],
                ]);
            $mock->shouldReceive('updateTutorChatVisibility')
                ->once()
                ->with('assistant-123', 'video-123', true)
                ->andReturn(['ok' => true]);
            $mock->shouldNotReceive('updateTutorStatus');
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('assist_chat.html?v=external-123', false)
            ->assertSee('tutorTabVisible: true', false)
            ->assertSee('Tutor da aula');

        $lesson->refresh();

        $this->assertTrue((bool) data_get($lesson->metadata, 'panda_ai.tutor_available'));
        $this->assertSame('assistant-123', data_get($lesson->metadata, 'panda_ai.tutor_assistant_id'));
    }

    public function test_lesson_page_hides_tutor_tab_when_tutor_is_not_enabled(): void
    {
        config(['services.panda.tutor_auto_detect' => true]);

        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => 'Classes de palavras sem tutor',
            'panda_video_id' => 'video-456',
            'panda_embed_url' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
            'metadata' => [
                'payload' => [
                    'video_external_id' => 'external-456',
                    'video_player' => 'https://player-vz-abc12345-abc.tv.pandavideo.com.br/embed/?v=external-456',
                ],
            ],
            'status' => 'published',
        ]);

        $this->mock(PandaVideoClient::class, function ($mock): void {
            $mock->shouldReceive('playerConfig')
                ->once()
                ->with('vz-abc12345-abc', 'external-456')
                ->andReturn(['ai' => ['questions' => true, 'mindmap' => true]]);
        });

        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertDontSee('Assistente da aula')
            ->assertDontSee('Estude este conteúdo com IA')
            ->assertDontSee('tutorTabVisible:', false)
            ->assertDontSee('tutorConfigUrl:', false)
            ->assertDontSee('tutorCandidateUrl:', false);

        $lesson->refresh();

        $this->assertFalse((bool) data_get($lesson->metadata, 'panda_ai.tutor_available'));
        $this->assertNotEmpty(data_get($lesson->metadata, 'panda_ai.tutor_checked_at'));
    }

    public function test_lesson_admin_ai_and_tutor_flags_reflect_ready_resources(): void
    {
        $lesson = Lesson::factory()->create([
            'metadata' => [
                'panda_ai' => [
                    'tutor_available' => true,
                    'tutor_status' => 'active',
                ],
            ],
        ]);

        foreach (['summary', 'quiz', 'mindmap'] as $type) {
            AiArtifact::query()->create([
                'source_type' => Lesson::class,
                'source_id' => $lesson->id,
                'artifact_type' => $type,
                'provider' => 'panda',
                'status' => 'ready',
                'content' => ['text' => "Conteúdo {$type}"],
            ]);
        }

        $this->assertSame('Completa', LessonResource::aiResourcesStatus($lesson));
        $this->assertSame('Ativo', LessonResource::tutorStatusFlag($lesson));
    }
}
