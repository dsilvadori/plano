<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CourseSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_finds_lessons_from_own_courses_with_module_and_track_context(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        [$course, $lesson] = $this->courseWithLesson('Curso Português', 'Português', 'Classe de palavras', '01 - Substantivo e Adjetivo');
        $student->courses()->attach($course, ['source' => 'manual']);

        LessonProgress::query()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'status' => 'completed',
            'progress_seconds' => 0,
        ]);

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'substantivo']))
            ->assertOk()
            ->assertSee('Resultados para')
            ->assertSee('01 - Substantivo e Adjetivo')
            ->assertSee('Português / Classe de palavras')
            ->assertSee('Concluída')
            ->assertSee(route('courses.lessons.show', [$course->slug, $lesson]), false);
    }

    public function test_every_search_word_must_match_the_lesson_title(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        [$course] = $this->courseWithLesson('Curso Português', 'Português', 'Crase', '01 - Crase antes de pronomes');
        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'crase pronomes']))
            ->assertOk()
            ->assertSee('01 - Crase antes de pronomes');

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'crase verbos']))
            ->assertOk()
            ->assertDontSee('01 - Crase antes de pronomes')
            ->assertSee('Nenhuma aula encontrada.');
    }

    public function test_lessons_from_courses_without_access_are_hidden_but_course_is_listed(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        [$course, $lesson] = $this->courseWithLesson('Curso Bloqueado de Direito', 'Direito', 'Constitucional', '01 - Direitos Fundamentais');

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'direito']))
            ->assertOk()
            ->assertSee('Curso Bloqueado de Direito')
            ->assertDontSee('01 - Direitos Fundamentais')
            ->assertDontSee(route('courses.lessons.show', [$course->slug, $lesson]), false);
    }

    public function test_archived_lessons_and_unpublished_courses_are_not_returned(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        [$course, $lesson] = $this->courseWithLesson('Curso Ativo', 'Matemática', 'Frações', '01 - Frações Equivalentes');
        $student->courses()->attach($course, ['source' => 'manual']);
        $lesson->forceFill(['status' => 'archived'])->save();

        [$draftCourse] = $this->courseWithLesson('Curso Rascunho Frações', 'Matemática 2', 'Frações 2', '02 - Frações Próprias');
        $draftCourse->forceFill(['status' => 'draft'])->save();
        $student->courses()->attach($draftCourse, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'frações']))
            ->assertOk()
            ->assertDontSee('01 - Frações Equivalentes')
            ->assertDontSee('02 - Frações Próprias')
            ->assertDontSee('Curso Rascunho Frações');
    }

    public function test_short_term_shows_instructions_without_results(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => 'a']))
            ->assertOk()
            ->assertSee('Encontre cursos e aulas')
            ->assertSee('Digite pelo menos 2 letras.');
    }

    public function test_like_wildcards_in_search_term_are_not_treated_as_wildcards(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        [$course] = $this->courseWithLesson('Curso Português', 'Português', 'Crase', '01 - Crase');
        $student->courses()->attach($course, ['source' => 'manual']);

        $this->actingAs($student)
            ->get(route('courses.search', ['q' => '%%']))
            ->assertOk()
            ->assertDontSee('01 - Crase');
    }

    public function test_navigation_shows_search_form_for_students(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($student)
            ->get(route('courses.mine'))
            ->assertOk()
            ->assertSee('action="'.route('courses.search').'"', false)
            ->assertSee('Buscar cursos e aulas');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('courses.search', ['q' => 'português']))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array{0: Course, 1: Lesson}
     */
    protected function courseWithLesson(string $courseName, string $moduleName, string $trackName, string $lessonTitle): array
    {
        $course = Course::factory()->create([
            'name' => $courseName,
            'status' => 'published',
            'is_active' => true,
        ]);
        $module = CourseModule::factory()->create([
            'course_id' => null,
            'name' => $moduleName,
            'is_active' => true,
        ]);
        $track = CourseModuleTrack::query()->create([
            'course_module_id' => $module->id,
            'name' => $trackName,
            'slug' => Str::slug($trackName),
            'sort_order' => 1,
            'status' => 'published',
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => null,
            'course_module_id' => null,
            'course_module_track_id' => null,
            'title' => $lessonTitle,
            'status' => 'published',
            'source_status' => 'media_ready',
            'panda_embed_url' => 'https://player.test/'.Str::slug($lessonTitle),
        ]);

        $module->courses()->attach($course->id, ['sort_order' => 1]);
        $track->lessons()->attach($lesson->id, ['sort_order' => 1]);
        $lesson->modules()->attach($module->id, ['sort_order' => 1]);

        return [$course, $lesson];
    }
}
