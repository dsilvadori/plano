<?php

namespace Tests\Feature;

use App\Jobs\GenerateQuestionCommentary;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\StudyPlan;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Services\GeminiQuestionCommentaryGenerator;
use App\Services\QuestionLessonLinker;
use App\Services\QuestionPdfImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuestionBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_parser_extracts_numbered_questions_and_trims_next_header_from_last_option(): void
    {
        $text = <<<'TXT'
VUNESP - Cargo/Org/2026
Língua Portuguesa (Português) - Substantivo
1)
Assinale a alternativa correta.
a) primeira alternativa.
b) segunda alternativa.
c) terceira alternativa.
d) quarta alternativa.

VUNESP - Outro Cargo/Org/2026
Língua Portuguesa (Português) - Adjetivo
2)
Assinale a alternativa incorreta.
a) alfa.
b) beta.
c) gama.
d) delta.
TXT;

        $questions = app(QuestionPdfImporter::class)->parseText($text);

        $this->assertCount(2, $questions);
        $this->assertSame(1, $questions[0]['number']);
        $this->assertSame('Substantivo', $questions[0]['topic']);
        $this->assertCount(4, $questions[0]['options']);
        $this->assertSame('quarta alternativa.', $questions[0]['options'][3]['text']);
    }

    public function test_answer_key_can_be_applied_after_pdf_import(): void
    {
        $bank = QuestionBank::query()->create([
            'title' => 'Banco importado',
            'source_type' => 'pdf',
            'status' => 'draft',
        ]);

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 1,
            'statement' => 'Assinale a correta.',
            'type' => 'multiple_choice',
            'status' => 'review',
        ]);

        $question->options()->create(['label' => 'a', 'text' => 'Errada', 'sort_order' => 1]);
        $correct = $question->options()->create(['label' => 'c', 'text' => 'Correta', 'sort_order' => 2]);

        $updated = app(QuestionPdfImporter::class)->applyAnswerKey($bank, '1:C');

        $this->assertSame(1, $updated);
        $this->assertSame('published', $bank->fresh()->status);
        $this->assertSame('c', $question->fresh()->answer_key);
        $this->assertTrue($correct->fresh()->is_correct);
    }

    public function test_pdf_importer_uses_gemini_fallback_when_local_text_extraction_fails(): void
    {
        config([
            'services.gemini.api_key' => 'testing-key',
            'services.gemini.model' => 'gemini-2.5-flash-lite',
        ]);

        Storage::disk('local')->put('question-banks/scanned.pdf', 'not a text pdf');

        Http::fake([
            '*gemini-2.5-flash-lite*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => <<<'TXT'
VUNESP - Cargo/Org/2026
Língua Portuguesa (Português) - Substantivo
1)
Assinale a alternativa correta.
a) primeira alternativa.
b) segunda alternativa.
c) terceira alternativa.
d) quarta alternativa.

1 - Gabarito
1 c
TXT,
                        ]],
                    ],
                ]],
            ]),
        ]);

        $bank = QuestionBank::query()->create([
            'title' => 'Banco escaneado',
            'source_type' => 'pdf',
            'source_file_path' => 'question-banks/scanned.pdf',
            'status' => 'draft',
        ]);

        $batch = app(QuestionPdfImporter::class)->import($bank);

        $this->assertSame('imported', $batch->status);
        $this->assertSame(1, $batch->questions_imported);
        $this->assertDatabaseHas('questions', [
            'question_bank_id' => $bank->id,
            'number' => 1,
            'answer_key' => 'c',
        ]);
    }

    public function test_pdf_reimport_replaces_existing_questions_and_reads_answer_key_from_final_page(): void
    {
        config([
            'services.gemini.api_key' => 'testing-key',
            'services.gemini.model' => 'gemini-2.5-flash-lite',
        ]);

        Storage::disk('local')->put('question-banks/reimport.pdf', 'not a text pdf');

        Http::fake([
            '*gemini-2.5-flash-lite*' => Http::sequence()
                ->push([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => <<<'TXT'
VUNESP - Cargo/Org/2026
Língua Portuguesa (Português) - Substantivo
1)
Assinale a alternativa correta.
a) primeira alternativa.
b) segunda alternativa.
c) terceira alternativa.
d) quarta alternativa.
TXT,
                            ]],
                        ],
                    ]],
                ])
                ->push([
                    'candidates' => [[
                        'content' => [
                            'parts' => [[
                                'text' => '1:C',
                            ]],
                        ],
                    ]],
                ]),
        ]);

        $bank = QuestionBank::query()->create([
            'title' => 'Banco reimportado',
            'source_type' => 'pdf',
            'source_file_path' => 'question-banks/reimport.pdf',
            'status' => 'published',
        ]);

        $staleQuestion = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 99,
            'statement' => 'Questão antiga que deve sair.',
            'type' => 'multiple_choice',
            'status' => 'published',
        ]);

        $batch = app(QuestionPdfImporter::class)->import($bank);

        $this->assertSame(1, $batch->questions_imported);
        $this->assertSame(1, data_get($batch->summary, 'removed_questions'));
        $this->assertDatabaseMissing('questions', [
            'id' => $staleQuestion->id,
        ]);
        $this->assertDatabaseHas('questions', [
            'question_bank_id' => $bank->id,
            'number' => 1,
            'answer_key' => 'c',
            'status' => 'published',
        ]);
    }

    public function test_student_can_open_question_bank_answer_and_see_commentary(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $student->courses()->attach($course, ['source' => 'manual']);

        $bank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Substantivo e adjetivo',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'course_id' => $course->id,
            'number' => 1,
            'subject' => 'Língua Portuguesa',
            'topic' => 'Substantivo',
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'commentary' => 'Substantivo dá nome aos seres.',
            'status' => 'published',
        ]);

        $question->options()->create([
            'label' => 'a',
            'text' => 'Rapidamente',
            'is_correct' => false,
            'sort_order' => 1,
        ]);
        $correct = $question->options()->create([
            'label' => 'b',
            'text' => 'Casa',
            'is_correct' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($student)
            ->get(route('questions.index'))
            ->assertOk()
            ->assertSee('Substantivo e adjetivo');

        $this->actingAs($student)
            ->post(route('questions.answer', $question), [
                'question_option_id' => $correct->id,
                'return_url' => route('questions.show', $bank),
            ])
            ->assertRedirect(route('questions.show', $bank));

        $this->actingAs($student)
            ->postJson(route('questions.answer', $question), [
                'question_option_id' => $correct->id,
                'return_url' => route('questions.show', $bank),
            ])
            ->assertOk()
            ->assertJson([
                'question_id' => $question->id,
                'selected_option_id' => $correct->id,
                'is_correct' => true,
                'answer_key' => 'B',
                'commentary' => 'Substantivo dá nome aos seres.',
            ]);

        $this->assertDatabaseHas('question_attempts', [
            'user_id' => $student->id,
            'question_id' => $question->id,
            'question_option_id' => $correct->id,
            'is_correct' => true,
        ]);

        $this->actingAs($student)
            ->get(route('questions.show', $bank))
            ->assertOk()
            ->assertSee('Gabarito: B')
            ->assertSee('Substantivo dá nome aos seres.');
    }

    public function test_gemini_commentary_generator_falls_back_when_configured_model_is_unavailable_or_busy(): void
    {
        config([
            'services.gemini.api_key' => 'testing-key',
            'services.gemini.model' => 'gemini-3.5-flash',
        ]);

        Http::fake([
            '*gemini-3.5-flash*' => Http::response([
                'error' => [
                    'code' => 503,
                    'message' => 'This model is currently experiencing high demand.',
                ],
            ], 503),
            '*gemini-2.5-flash-lite*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'A alternativa correta é B porque substantivo nomeia seres.',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $bank = QuestionBank::query()->create([
            'title' => 'Banco importado',
            'source_type' => 'pdf',
            'status' => 'draft',
        ]);

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 1,
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'status' => 'review',
        ]);

        $question->options()->create(['label' => 'a', 'text' => 'Rapidamente', 'sort_order' => 1]);
        $question->options()->create(['label' => 'b', 'text' => 'Casa', 'sort_order' => 2]);

        $generator = app(GeminiQuestionCommentaryGenerator::class);

        $this->assertSame(
            'A alternativa correta é B porque substantivo nomeia seres.',
            $generator->generate($question)
        );
        $this->assertSame('gemini-2.5-flash-lite', $generator->lastModel());
    }

    public function test_question_commentary_job_generates_and_saves_commentary(): void
    {
        config([
            'services.gemini.api_key' => 'testing-key',
            'services.gemini.model' => 'gemini-2.5-flash-lite',
        ]);

        Http::fake([
            '*gemini-2.5-flash-lite*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => 'A alternativa B está correta porque casa é um substantivo.',
                        ]],
                    ],
                ]],
            ]),
        ]);

        $bank = QuestionBank::query()->create([
            'title' => 'Banco importado',
            'source_type' => 'pdf',
            'status' => 'draft',
        ]);

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 1,
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'status' => 'review',
        ]);

        $question->options()->create(['label' => 'a', 'text' => 'Rapidamente', 'sort_order' => 1]);
        $question->options()->create(['label' => 'b', 'text' => 'Casa', 'sort_order' => 2]);

        app(GenerateQuestionCommentary::class, [
            'questionId' => $question->id,
        ])->handle(app(GeminiQuestionCommentaryGenerator::class));

        $this->assertSame(
            'A alternativa B está correta porque casa é um substantivo.',
            $question->fresh()->commentary
        );
        $this->assertSame('gemini:gemini-2.5-flash-lite', $question->fresh()->commentary_provider);
    }

    public function test_question_bank_questions_can_be_linked_to_matching_lesson_subjects(): void
    {
        $course = Course::factory()->create(['status' => 'published']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => '01 - Classes de Palavras - Substantivo e Adjetivo',
        ]);

        $bank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Classe de palavras - Substantivo e Adjetivo',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);

        $question = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 1,
            'subject' => 'Português',
            'topic' => 'Substantivo',
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'status' => 'published',
        ]);

        $this->assertSame(1, app(QuestionLessonLinker::class)->linkBank($bank));

        $this->assertSame($course->id, $question->fresh()->course_id);
        $this->assertSame($module->id, $question->fresh()->course_module_id);
        $this->assertSame($lesson->id, $question->fresh()->lesson_id);
    }

    public function test_study_plan_links_to_related_questions_and_question_page_links_back_to_plan(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $student->courses()->attach($course, ['source' => 'manual']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
        ]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'type' => 'questions',
            'title' => 'Resolução de Questões: Português',
            'description' => 'Pratique o conteúdo estudado.',
        ]);

        $bank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Português - Gramática',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);
        Question::query()->create([
            'question_bank_id' => $bank->id,
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'number' => 1,
            'topic' => 'Gramática',
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'status' => 'published',
        ]);

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Resolver questões: Português')
            ->assertSee('plan_id='.$plan->id.'&amp;module_id='.$module->id, false);

        $this->actingAs($student)
            ->get(route('questions.show', [$bank, 'plan_id' => $plan->id, 'module_id' => $module->id]))
            ->assertOk()
            ->assertSee('Voltar para o plano')
            ->assertSee('Qual alternativa apresenta um substantivo?');
    }

    public function test_question_plan_block_lists_related_question_links_for_theory_modules_from_same_day(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $student->courses()->attach($course, ['source' => 'manual']);
        $portuguese = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
        ]);
        $administration = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Administração Geral',
            'type' => 'specific',
        ]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $portuguese->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'basic',
            'sort_order' => 1,
        ]);
        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $administration->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'specific',
            'sort_order' => 2,
        ]);
        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $administration->id,
            'scheduled_date' => now()->toDateString(),
            'type' => 'questions',
            'title' => 'Bloco de questões',
            'sort_order' => 3,
        ]);

        $portugueseBank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Português - Gramática',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);
        $administrationBank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Administração Geral',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);

        Question::query()->create([
            'question_bank_id' => $portugueseBank->id,
            'course_id' => $course->id,
            'course_module_id' => $portuguese->id,
            'number' => 1,
            'statement' => 'Questão de português.',
            'type' => 'multiple_choice',
            'answer_key' => 'a',
            'status' => 'published',
        ]);
        Question::query()->create([
            'question_bank_id' => $administrationBank->id,
            'course_id' => $course->id,
            'course_module_id' => $administration->id,
            'number' => 1,
            'statement' => 'Questão de administração.',
            'type' => 'multiple_choice',
            'answer_key' => 'a',
            'status' => 'published',
        ]);

        $this->actingAs($student)
            ->get(route('study-plans.show', $plan))
            ->assertOk()
            ->assertSee('Resolver questões: Português')
            ->assertSee('Resolver questões: Administração Geral')
            ->assertSee('plan_id='.$plan->id.'&amp;module_id='.$portuguese->id, false)
            ->assertSee('plan_id='.$plan->id.'&amp;module_id='.$administration->id, false);
    }

    public function test_lesson_page_shows_fixation_tab_and_related_question_links(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $course = Course::factory()->create(['status' => 'published']);
        $student->courses()->attach($course, ['source' => 'manual']);
        $module = CourseModule::factory()->create([
            'course_id' => $course->id,
            'name' => 'Português',
            'type' => 'basic',
        ]);
        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'title' => '01 - Classes de Palavras - Substantivo e Adjetivo',
        ]);

        $plan = StudyPlan::factory()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
        ]);
        $theoryItem = StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'type' => 'basic',
            'title' => 'Português',
        ]);
        $theoryItem->lessons()->attach($lesson->id, ['sort_order' => 1]);
        StudyPlanItem::factory()->create([
            'study_plan_id' => $plan->id,
            'course_module_id' => $module->id,
            'type' => 'questions',
            'title' => 'Resolução de Questões: Português',
        ]);

        $bank = QuestionBank::query()->create([
            'course_id' => $course->id,
            'title' => 'Classe de palavras - Substantivo e Adjetivo',
            'source_type' => 'pdf',
            'status' => 'published',
        ]);
        Question::query()->create([
            'question_bank_id' => $bank->id,
            'course_id' => $course->id,
            'course_module_id' => $module->id,
            'lesson_id' => $lesson->id,
            'number' => 1,
            'topic' => 'Substantivo',
            'statement' => 'Qual alternativa apresenta um substantivo?',
            'type' => 'multiple_choice',
            'answer_key' => 'b',
            'status' => 'published',
        ]);

        $this->actingAs($student)
            ->get(route('courses.lessons.show', [$course->slug, $lesson]))
            ->assertOk()
            ->assertSee('Questões para fixação')
            ->assertSee('Resolução de questões')
            ->assertSee('Pratique este assunto no banco de questões.')
            ->assertSee('Abrir área de questões')
            ->assertSee('lesson_id='.$lesson->id.'&amp;plan_id='.$plan->id, false);
    }
}
