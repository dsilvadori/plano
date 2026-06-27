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
use App\Services\QuestionSpreadsheetImporter;
use App\Support\QuestionTextRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

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

    public function test_pdf_parser_splits_inline_options_out_of_statement(): void
    {
        $text = <<<'TXT'
VUNESP - Cargo/Org/2026
Língua Portuguesa (Português) - Interpretação de texto
1)
No trecho apresentado, assinale a alternativa correta. a) primeira alternativa. b) segunda alternativa. c) terceira alternativa. d) quarta alternativa.
TXT;

        $questions = app(QuestionPdfImporter::class)->parseText($text);

        $this->assertCount(1, $questions);
        $this->assertSame('No trecho apresentado, assinale a alternativa correta.', $questions[0]['statement']);
        $this->assertCount(4, $questions[0]['options']);
        $this->assertSame('primeira alternativa.', $questions[0]['options'][0]['text']);
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

    public function test_xlsx_import_replaces_existing_questions_and_imports_commentary(): void
    {
        $bank = QuestionBank::query()->create([
            'title' => 'Banco XLSX',
            'source_type' => 'xlsx',
            'status' => 'draft',
        ]);

        $oldQuestion = Question::query()->create([
            'question_bank_id' => $bank->id,
            'number' => 99,
            'statement' => 'Questão antiga.',
            'type' => 'multiple_choice',
            'status' => 'published',
        ]);

        $path = $this->createQuestionSpreadsheet([
            [
                'numero',
                'disciplina',
                'assunto',
                'subassunto',
                'enunciado',
                'alternativa_a',
                'alternativa_b',
                'alternativa_c',
                'alternativa_d',
                'alternativa_e',
                'gabarito',
                'comentario',
                'referencia_origem',
                'observacoes_revisao',
                'imagem_url',
                'imagem_descricao',
            ],
            [
                '1',
                'Língua Portuguesa',
                'Substantivo',
                '',
                'Qual alternativa apresenta um **substantivo**?',
                'Rapidamente',
                'Casa',
                'Ontem',
                'Muito',
                '',
                'B',
                'Casa nomeia um ser, por isso é substantivo.',
                'VUNESP - Cargo/2026',
                '',
                'question-images/substantivo.png',
                'Imagem com uma casa usada como exemplo de substantivo.',
            ],
        ]);

        $batch = app(QuestionSpreadsheetImporter::class)->import($bank, $path);

        $this->assertSame('imported', $batch->status);
        $this->assertSame(1, $batch->questions_imported);
        $this->assertDatabaseMissing('questions', ['id' => $oldQuestion->id]);
        $this->assertDatabaseHas('questions', [
            'question_bank_id' => $bank->id,
            'number' => 1,
            'subject' => 'Língua Portuguesa',
            'topic' => 'Substantivo',
            'statement' => 'Qual alternativa apresenta um **substantivo**?',
            'answer_key' => 'b',
            'commentary' => 'Casa nomeia um ser, por isso é substantivo.',
            'commentary_provider' => 'xlsx',
            'source_reference' => 'VUNESP - Cargo/2026',
            'status' => 'published',
        ]);
        $question = $bank->questions()->firstOrFail();

        $this->assertSame(['/storage/question-images/substantivo.png'], data_get($question->metadata, 'image_urls'));
        $this->assertSame('Imagem com uma casa usada como exemplo de substantivo.', data_get($question->metadata, 'image_description'));
        $this->assertDatabaseHas('question_options', [
            'label' => 'b',
            'text' => 'Casa',
            'is_correct' => true,
        ]);
    }

    public function test_question_import_relabels_duplicate_option_letters(): void
    {
        $bank = QuestionBank::query()->create([
            'title' => 'Banco com alternativa duplicada',
            'source_type' => 'pdf',
            'status' => 'draft',
        ]);

        app(QuestionSpreadsheetImporter::class)->importParsedQuestions($bank, [[
            'number' => 1,
            'subject' => null,
            'topic' => null,
            'subtopic' => null,
            'statement' => 'Assinale a correta.',
            'options' => [
                ['label' => 'a', 'text' => 'Alternativa A'],
                ['label' => 'b', 'text' => 'Alternativa B'],
                ['label' => 'c', 'text' => 'Alternativa C'],
                ['label' => 'd', 'text' => 'Alternativa D'],
                ['label' => 'd', 'text' => 'Alternativa que deveria ser E'],
            ],
            'answer_key' => 'e',
            'commentary' => null,
            'source_reference' => null,
            'review_notes' => null,
        ]]);

        $question = $bank->questions()->firstOrFail();

        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $question->options()->pluck('label')->all());
        $this->assertDatabaseHas('question_options', [
            'question_id' => $question->id,
            'label' => 'e',
            'text' => 'Alternativa que deveria ser E',
        ]);
    }

    public function test_commentary_renderer_breaks_each_option_explanation_into_its_own_line(): void
    {
        $html = (string) QuestionTextRenderer::renderCommentary(
            'A - Está incorreta. B - Está incorreta. C - Está correta porque é **substantivo**. D - Está incorreta. E - Está incorreta.'
        );

        $this->assertSame(5, substr_count($html, '<p>'));
        $this->assertStringContainsString('<p>A - Está incorreta.</p>', $html);
        $this->assertStringContainsString('<p>B - Está incorreta.</p>', $html);
        $this->assertStringContainsString('<p>C - Está correta porque é <strong>substantivo</strong>.</p>', $html);
        $this->assertStringContainsString('<p>D - Está incorreta.</p>', $html);
        $this->assertStringContainsString('<p>E - Está incorreta.</p>', $html);
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
                            'text' => json_encode([
                                'questions' => [[
                                    'numero' => 1,
                                    'disciplina' => 'Língua Portuguesa',
                                    'assunto' => 'Substantivo',
                                    'enunciado' => 'Assinale a alternativa correta.',
                                    'alternativa_a' => 'primeira alternativa.',
                                    'alternativa_b' => 'segunda alternativa.',
                                    'alternativa_c' => 'terceira alternativa.',
                                    'alternativa_d' => 'quarta alternativa.',
                                    'gabarito' => 'C',
                                    'comentario' => 'A alternativa C está correta.',
                                ]],
                            ]),
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

    protected function createQuestionSpreadsheet(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'questions-xlsx-');

        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::OVERWRITE);
        $archive->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);
        $archive->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML);
        $archive->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Questões" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML);
        $archive->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

        $sheetRows = collect($rows)
            ->map(function (array $row, int $rowIndex): string {
                $rowNumber = $rowIndex + 1;
                $cells = collect($row)
                    ->map(function (string $value, int $columnIndex) use ($rowNumber): string {
                        $column = chr(65 + $columnIndex);
                        $escaped = htmlspecialchars($value, ENT_XML1);

                        return "<c r=\"{$column}{$rowNumber}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
                    })
                    ->implode('');

                return "<row r=\"{$rowNumber}\">{$cells}</row>";
            })
            ->implode('');

        $archive->addFromString('xl/worksheets/sheet1.xml', <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>{$sheetRows}</sheetData>
</worksheet>
XML);
        $archive->close();

        return $path;
    }

    public function test_pdf_reimport_replaces_existing_questions_and_reads_answer_key_from_final_page(): void
    {
        config([
            'services.gemini.api_key' => 'testing-key',
            'services.gemini.model' => 'gemini-2.5-flash-lite',
        ]);

        Storage::disk('local')->put('question-banks/reimport.pdf', 'not a text pdf');

        Http::fake([
            '*gemini-2.5-flash-lite*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'questions' => [[
                                    'numero' => 1,
                                    'disciplina' => 'Língua Portuguesa',
                                    'assunto' => 'Substantivo',
                                    'enunciado' => 'Assinale a alternativa correta.',
                                    'alternativa_a' => 'primeira alternativa.',
                                    'alternativa_b' => 'segunda alternativa.',
                                    'alternativa_c' => 'terceira alternativa.',
                                    'alternativa_d' => 'quarta alternativa.',
                                    'gabarito' => 'C',
                                    'comentario' => 'A alternativa C está correta.',
                                ]],
                            ]),
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

    public function test_question_bank_is_global_and_not_linked_to_course_lessons(): void
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

        $this->assertNull($bank->fresh()->course_id);
        $this->assertSame(0, app(QuestionLessonLinker::class)->linkBank($bank->fresh()));

        $this->assertNull($question->fresh()->course_id);
        $this->assertNull($question->fresh()->course_module_id);
        $this->assertNull($question->fresh()->lesson_id);
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
