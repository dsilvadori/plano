<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionImportBatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class QuestionPdfImporter
{
    protected const PDF_TO_XLSX_PROMPT = <<<'PROMPT'
Converta questões para uma estrutura de dados.
Campos obrigatórios: numero, disciplina, assunto, subassunto, enunciado, alternativa_a, alternativa_b, alternativa_c, alternativa_d, alternativa_e, gabarito, referencia_origem, observacoes_revisao, imagem_descricao.

Regras:
1. Cada linha representa exatamente uma questão.
2. O enunciado deve conter apenas o texto da questão, sem alternativas dentro dele.
3. As alternativas devem ficar separadas nas colunas alternativa_a, alternativa_b, alternativa_c, alternativa_d e alternativa_e.
4. Preserve negrito, grifos e destaques usando Markdown: **texto em negrito** e ==texto destacado==.
5. Se houver imagens, tabelas ou trechos que não possam ser convertidos bem, descreva em observacoes_revisao e, quando a imagem for necessária para resolver a questão, descreva objetivamente seu conteúdo em imagem_descricao.
6. Se uma alternativa parecer quebrada em várias linhas, junte em uma única célula.
7. Se houver gabarito no final, associe cada resposta à questão correspondente usando apenas A, B, C, D ou E.
8. Se não encontrar o gabarito, deixe gabarito vazio e escreva "gabarito não encontrado" em observacoes_revisao.
9. Não gere comentários explicativos nesta etapa.
10. Valide que nenhuma alternativa ficou no enunciado, todas as questões têm número, alternativas estão nos campos corretos e todo gabarito é letra válida.
PROMPT;

    public function import(QuestionBank $bank, ?int $userId = null): QuestionImportBatch
    {
        if (! $bank->source_file_path) {
            throw new RuntimeException('Envie um PDF antes de importar as questões.');
        }

        $pdfPath = Storage::disk('local')->exists($bank->source_file_path)
            ? Storage::disk('local')->path($bank->source_file_path)
            : Storage::disk('public')->path($bank->source_file_path);

        if (! is_file($pdfPath)) {
            throw new RuntimeException('Arquivo PDF não encontrado.');
        }

        $localText = trim($this->extractTextWithPdftotext($pdfPath));

        if ($localText !== '') {
            $locallyParsedQuestions = $this->parseText($localText);

            if ($locallyParsedQuestions !== []) {
                return app(QuestionSpreadsheetImporter::class)->importParsedQuestions(
                    $bank,
                    $this->normalizeTextParsedQuestions($locallyParsedQuestions),
                    $userId,
                    'pdf',
                    $bank->source_file_path,
                );
            }
        }

        $structuredQuestions = $this->extractStructuredQuestionsWithGemini($pdfPath);

        if ($structuredQuestions !== []) {
            return app(QuestionSpreadsheetImporter::class)->importParsedQuestions(
                $bank,
                $structuredQuestions,
                $userId,
                'pdf',
                $bank->source_file_path,
            );
        }

        $batch = QuestionImportBatch::query()->create([
            'course_id' => null,
            'question_bank_id' => $bank->id,
            'created_by' => $userId,
            'source_type' => 'pdf',
            'file_path' => $bank->source_file_path,
            'status' => 'extracting',
        ]);

        try {
            $text = $this->extractText($pdfPath);
            $parsedQuestions = $this->parseText($text);
            $parsedQuestions = $this->fillMissingAnswerKeys($parsedQuestions, $pdfPath);

            DB::transaction(function () use ($bank, $batch, $parsedQuestions, $text): void {
                $importedNumbers = collect($parsedQuestions)->pluck('number')->filter()->all();
                $removedQuestions = $bank->questions()->count();
                $bank->questions()->delete();

                foreach ($parsedQuestions as $parsedQuestion) {
                    $question = Question::query()->create([
                        'question_bank_id' => $bank->id,
                        'course_id' => null,
                        'number' => $parsedQuestion['number'],
                        'subject' => $parsedQuestion['subject'],
                        'topic' => $parsedQuestion['topic'],
                        'statement' => $parsedQuestion['statement'],
                        'type' => 'multiple_choice',
                        'answer_key' => $parsedQuestion['answer_key'],
                        'source_reference' => $parsedQuestion['source_reference'],
                        'status' => $parsedQuestion['answer_key'] ? 'published' : 'review',
                        'metadata' => [
                            'import_batch_id' => $batch->id,
                            'raw_question' => $parsedQuestion['raw'],
                        ],
                    ]);

                    foreach ($this->normalizeOptionLabels($parsedQuestion['options']) as $sortOrder => $option) {
                        $question->options()->create([
                            'label' => $option['label'],
                            'text' => $option['text'],
                            'is_correct' => $parsedQuestion['answer_key'] && strtolower($parsedQuestion['answer_key']) === strtolower($option['label']),
                            'sort_order' => $sortOrder + 1,
                        ]);
                    }
                }

                $batch->forceFill([
                    'status' => 'imported',
                    'questions_found' => count($parsedQuestions),
                    'questions_imported' => count($parsedQuestions),
                    'summary' => [
                        'text_length' => mb_strlen($text),
                        'without_answer_key' => collect($parsedQuestions)->whereNull('answer_key')->count(),
                        'imported_numbers' => $importedNumbers,
                        'removed_questions' => $removedQuestions,
                    ],
                ])->save();

                $bank->forceFill([
                    'course_id' => null,
                    'status' => collect($parsedQuestions)->contains(fn (array $question): bool => filled($question['answer_key'])) ? 'published' : 'draft',
                    'metadata' => array_replace($bank->metadata ?? [], [
                        'last_import_batch_id' => $batch->id,
                        'last_imported_at' => now()->toIso8601String(),
                        'questions_imported' => count($parsedQuestions),
                    ]),
                ])->save();
            });
        } catch (Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ])->save();

            throw $exception;
        }

        return $batch->fresh();
    }

    protected function normalizeOptionLabels(array $options): array
    {
        $availableLabels = ['a', 'b', 'c', 'd', 'e'];
        $usedLabels = [];

        return collect($options)
            ->map(function (array $option) use ($availableLabels, &$usedLabels): ?array {
                $text = trim((string) ($option['text'] ?? ''));

                if ($text === '') {
                    return null;
                }

                $label = Str::of((string) ($option['label'] ?? ''))->lower()->ascii()->value();

                if (! in_array($label, $availableLabels, true) || in_array($label, $usedLabels, true)) {
                    $label = collect($availableLabels)
                        ->first(fn (string $candidate): bool => ! in_array($candidate, $usedLabels, true));
                }

                if (! is_string($label)) {
                    return null;
                }

                $usedLabels[] = $label;

                return [
                    'label' => $label,
                    'text' => $text,
                ];
            })
            ->filter()
            ->take(5)
            ->values()
            ->all();
    }

    protected function normalizeTextParsedQuestions(array $parsedQuestions): array
    {
        return collect($parsedQuestions)
            ->map(fn (array $question): array => [
                'number' => $question['number'],
                'subject' => $question['subject'] ?? null,
                'topic' => $question['topic'] ?? null,
                'subtopic' => null,
                'statement' => $question['statement'],
                'options' => $question['options'],
                'answer_key' => $question['answer_key'] ?? null,
                'commentary' => null,
                'source_reference' => $question['source_reference'] ?? null,
                'review_notes' => null,
            ])
            ->values()
            ->all();
    }

    protected function extractStructuredQuestionsWithGemini(string $pdfPath): array
    {
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            return [];
        }

        $text = trim($this->extractTextWithPdftotext($pdfPath));

        if ($text !== '') {
            $questions = $this->extractStructuredQuestionsFromText($text);

            if ($questions !== []) {
                return $questions;
            }
        }

        $contents = file_get_contents($pdfPath);

        if ($contents === false) {
            return [];
        }

        $prompt = $this->structuredJsonPrompt()."\n\nLeia o PDF anexo e retorne as questões estruturadas.";

        foreach ($this->geminiModelsToTry(forPdfImport: true) as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                    ->acceptJson()
                    ->timeout(120)
                    ->post("/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => 'application/pdf',
                                        'data' => base64_encode($contents),
                                    ],
                                ],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 64000,
                        ],
                    ]);

                $response->throw();

                $questions = $this->parseStructuredQuestionsJson(
                    trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', '')),
                );

                if ($questions !== []) {
                    return $questions;
                }
            } catch (RequestException $exception) {
                if (in_array($exception->response->status(), [404, 429, 503], true)) {
                    continue;
                }

                throw $exception;
            } catch (ConnectionException) {
                continue;
            }
        }

        return [];
    }

    protected function extractStructuredQuestionsFromText(string $text): array
    {
        $prompt = $this->structuredJsonPrompt()."\n\nTexto extraído do PDF:\n\n".$text;

        foreach ($this->geminiModelsToTry(forPdfImport: true) as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                    ->acceptJson()
                    ->timeout(90)
                    ->post("/v1beta/models/{$model}:generateContent?key=".config('services.gemini.api_key'), [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 64000,
                        ],
                    ]);

                $response->throw();

                $questions = $this->parseStructuredQuestionsJson(
                    trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', '')),
                );

                if ($questions !== []) {
                    return $questions;
                }
            } catch (RequestException $exception) {
                if (in_array($exception->response->status(), [404, 429, 503], true)) {
                    continue;
                }

                throw $exception;
            } catch (ConnectionException) {
                continue;
            }
        }

        return [];
    }

    protected function structuredJsonPrompt(): string
    {
        return self::PDF_TO_XLSX_PROMPT."\n\n".
            'Para esta integração, não gere arquivo XLSX para download. Retorne somente JSON válido, sem markdown, no formato: '.
            '{"questions":[{"numero":1,"disciplina":"","assunto":"","subassunto":"","enunciado":"","alternativa_a":"","alternativa_b":"","alternativa_c":"","alternativa_d":"","alternativa_e":"","gabarito":"A","referencia_origem":"","observacoes_revisao":"","imagem_descricao":""}]}.';
    }

    protected function parseStructuredQuestionsJson(string $text): array
    {
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text;
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = isset($decoded['numero']) || isset($decoded['number'])
            ? [$decoded]
            : ($decoded['questions'] ?? $decoded);

        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(fn (mixed $row): ?array => is_array($row) ? $this->normalizeStructuredQuestion($row) : null)
            ->filter()
            ->values()
            ->all();
    }

    protected function normalizeStructuredQuestion(array $row): ?array
    {
        $number = (int) ($row['numero'] ?? $row['number'] ?? 0);
        $statement = trim((string) ($row['enunciado'] ?? $row['statement'] ?? ''));

        if ($number <= 0 || $statement === '') {
            return null;
        }

        $options = collect(['a', 'b', 'c', 'd', 'e'])
            ->map(function (string $label) use ($row): ?array {
                $text = trim((string) ($row["alternativa_{$label}"] ?? $row["alternativa {$label}"] ?? $row[$label] ?? ''));

                if ($text === '') {
                    return null;
                }

                return [
                    'label' => $label,
                    'text' => $text,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($options === []) {
            return null;
        }

        $answerKey = Str::of((string) ($row['gabarito'] ?? $row['answer_key'] ?? ''))->trim()->lower()->ascii()->value();

        return [
            'number' => $number,
            'subject' => trim((string) ($row['disciplina'] ?? '')) ?: null,
            'topic' => trim((string) ($row['assunto'] ?? '')) ?: null,
            'subtopic' => trim((string) ($row['subassunto'] ?? '')) ?: null,
            'statement' => $statement,
            'options' => $options,
            'answer_key' => in_array($answerKey, ['a', 'b', 'c', 'd', 'e'], true) ? $answerKey : null,
            'commentary' => null,
            'source_reference' => trim((string) ($row['referencia_origem'] ?? '')) ?: null,
            'review_notes' => trim((string) ($row['observacoes_revisao'] ?? '')) ?: null,
            'image_urls' => $this->imageUrlsFromStructuredQuestion($row),
            'image_description' => trim((string) ($row['imagem_descricao'] ?? $row['descricao_imagem'] ?? '')) ?: null,
        ];
    }

    protected function imageUrlsFromStructuredQuestion(array $row): array
    {
        $values = [
            $row['imagem_url'] ?? null,
            $row['imagem'] ?? null,
            $row['imagem_1'] ?? null,
            $row['imagem_2'] ?? null,
            $row['imagem_3'] ?? null,
        ];

        if (isset($row['imagens']) && is_array($row['imagens'])) {
            $values = array_merge($values, $row['imagens']);
        }

        return collect($values)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->flatMap(fn (string $value): array => preg_split('/[\n;,|]+/', $value) ?: [])
            ->map(fn (string $value): ?string => $this->normalizeImageUrl($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function normalizeImageUrl(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', '//', '/'])) {
            return $value;
        }

        if (Str::startsWith($value, 'storage/')) {
            return '/'.$value;
        }

        return '/storage/'.ltrim($value, '/');
    }

    public function applyAnswerKey(QuestionBank $bank, string $answerKeyText): int
    {
        $answerKey = $this->parseInlineAnswerKey($answerKeyText);
        $updated = 0;

        foreach ($answerKey as $number => $label) {
            $question = $bank->questions()->where('number', $number)->first();

            if (! $question) {
                continue;
            }

            $question->forceFill([
                'answer_key' => $label,
                'status' => 'published',
            ])->save();

            $question->options()->update(['is_correct' => false]);
            $question->options()
                ->whereRaw('lower(label) = ?', [strtolower($label)])
                ->update(['is_correct' => true]);

            $updated++;
        }

        if ($updated > 0) {
            $bank->forceFill(['status' => 'published'])->save();
        }

        return $updated;
    }

    public function extractText(string $pdfPath): string
    {
        $text = $this->extractTextWithPdftotext($pdfPath);

        if (trim($text) !== '') {
            return $text;
        }

        $text = $this->extractTextWithGemini($pdfPath);

        if (trim($text) !== '') {
            return $text;
        }

        throw new RuntimeException('Não foi possível extrair texto do PDF. Verifique se o arquivo não está protegido ou escaneado.');
    }

    protected function extractTextWithPdftotext(string $pdfPath): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'questions-pdf-');

        if ($outputPath === false) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para leitura do PDF.');
        }

        try {
            $process = new Process(['pdftotext', '-layout', $pdfPath, $outputPath]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful()) {
                return '';
            }

            return (string) file_get_contents($outputPath);
        } catch (Throwable) {
            return '';
        } finally {
            @unlink($outputPath);
        }
    }

    protected function extractTextWithGemini(string $pdfPath): string
    {
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Não foi possível extrair texto do PDF automaticamente. Configure GEMINI_API_KEY ou instale o pdftotext/poppler-utils no servidor.');
        }

        $contents = file_get_contents($pdfPath);

        if ($contents === false) {
            return '';
        }

        $prompt = implode("\n", [
            'Extraia o texto deste PDF de questões de concurso.',
            'Preserve a numeração das questões no formato "1)" e preserve as alternativas no formato "a)", "b)", "c)", "d)" e "e)".',
            'Quando houver texto em negrito, destaque ou grifo, represente como Markdown: **negrito** e ==destaque==.',
            'Preserve também a seção de gabarito, se existir.',
            'Retorne apenas o texto extraído, sem comentários adicionais.',
        ]);
        $lastException = null;

        foreach ($this->geminiModelsToTry(forPdfImport: true) as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                    ->acceptJson()
                    ->timeout(90)
                    ->post("/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => 'application/pdf',
                                        'data' => base64_encode($contents),
                                    ],
                                ],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 32000,
                        ],
                    ]);

                $response->throw();

                return trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
            } catch (RequestException $exception) {
                $lastException = $exception;

                if (in_array($exception->response->status(), [404, 429, 503], true)) {
                    continue;
                }

                throw $exception;
            } catch (ConnectionException $exception) {
                $lastException = $exception;

                continue;
            }
        }

        if ($lastException) {
            throw $lastException;
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    protected function geminiModelsToTry(bool $forPdfImport = false): array
    {
        $configuredModel = trim((string) config('services.gemini.model'), '/');

        $models = $forPdfImport ? [
            $configuredModel,
            'gemini-2.5-flash-lite',
            'gemini-2.5-flash',
            'gemini-flash-latest',
        ] : [
            $configuredModel,
            'gemini-2.5-flash-lite',
            'gemini-3.5-flash',
            'gemini-2.5-flash',
            'gemini-flash-latest',
        ];

        return collect($models)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function parseText(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/^\s*\d+\s+de\s+\d+\s*$/mi', '', $text) ?? $text;
        $text = preg_replace('/^\s*Caderno de Questões\s*$/mi', '', $text) ?? $text;

        $answerKey = $this->parseAnswerKey($text);
        $questionText = preg_split('/^\s*\d+\s+[–-]\s+Gabarito\s*$/mi', $text)[0] ?? $text;

        preg_match_all('/^\s*(\d{1,3})\)\s*$/m', $questionText, $matches, PREG_OFFSET_CAPTURE);

        $questions = [];
        $questionStarts = $matches[0];

        foreach ($questionStarts as $index => $match) {
            $number = (int) $matches[1][$index][0];
            $start = $match[1] + strlen($match[0]);
            $end = $questionStarts[$index + 1][1] ?? strlen($questionText);
            $raw = trim(substr($questionText, $start, $end - $start));

            if ($raw === '') {
                continue;
            }

            $parsed = $this->parseQuestionBlock($raw);

            if ($parsed['statement'] === '' || $parsed['options'] === []) {
                continue;
            }

            $sourceReference = $this->extractSourceReference($questionText, $match[1]);
            [$subject, $topic] = $this->extractSubjectAndTopic($sourceReference, $raw);

            $questions[] = [
                'number' => $number,
                'subject' => $subject,
                'topic' => $topic,
                'statement' => $parsed['statement'],
                'options' => $parsed['options'],
                'answer_key' => $answerKey[$number] ?? null,
                'source_reference' => $sourceReference,
                'raw' => $raw,
            ];
        }

        return $questions;
    }

    protected function parseQuestionBlock(string $raw): array
    {
        $raw = $this->normalizeOptionMarkers($raw);
        preg_match_all('/^\s*([a-e])\)\s*(.*?)(?=^\s*[a-e]\)\s*|\z)/ims', $raw, $matches, PREG_OFFSET_CAPTURE);

        if ($matches[0] === []) {
            return ['statement' => $this->cleanText($raw), 'options' => []];
        }

        $firstOptionPosition = $matches[0][0][1];
        $statement = $this->cleanText(substr($raw, 0, $firstOptionPosition));
        $options = [];

        foreach ($matches[0] as $index => $fullMatch) {
            $options[] = [
                'label' => strtolower($matches[1][$index][0]),
                'text' => $this->cleanOptionText($matches[2][$index][0]),
            ];
        }

        return [
            'statement' => $statement,
            'options' => collect($options)
                ->filter(fn (array $option) => $option['text'] !== '')
                ->values()
                ->all(),
        ];
    }

    protected function parseAnswerKey(string $text): array
    {
        $answerSection = preg_split('/^\s*\d+\s+[–-]\s+Gabarito\s*$/mi', $text)[1] ?? '';

        if ($answerSection === '') {
            return [];
        }

        preg_match_all('/\b(\d{1,3})\s*[-.)]?\s*([a-e])\b/i', $answerSection, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [(int) $match[1] => strtolower($match[2])])
            ->all();
    }

    protected function fillMissingAnswerKeys(array $parsedQuestions, string $pdfPath): array
    {
        if ($parsedQuestions === [] || collect($parsedQuestions)->every(fn (array $question): bool => filled($question['answer_key']))) {
            return $parsedQuestions;
        }

        $answerKey = $this->extractAnswerKeyWithGemini($pdfPath);

        if ($answerKey === []) {
            return $parsedQuestions;
        }

        return collect($parsedQuestions)
            ->map(function (array $question) use ($answerKey): array {
                $question['answer_key'] = $question['answer_key'] ?: ($answerKey[$question['number']] ?? null);

                return $question;
            })
            ->all();
    }

    protected function extractAnswerKeyWithGemini(string $pdfPath): array
    {
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            return [];
        }

        $contents = file_get_contents($pdfPath);

        if ($contents === false) {
            return [];
        }

        $prompt = implode("\n", [
            'Leia este PDF de questões de concurso e extraia somente o gabarito final.',
            'O gabarito normalmente fica na última página ou no final do arquivo.',
            'Retorne apenas pares no formato "1:A,2:B,3:C".',
            'Não explique nada e não inclua texto fora dos pares número:alternativa.',
        ]);

        foreach ($this->geminiModelsToTry(forPdfImport: true) as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                    ->acceptJson()
                    ->timeout(90)
                    ->post("/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                                [
                                    'inline_data' => [
                                        'mime_type' => 'application/pdf',
                                        'data' => base64_encode($contents),
                                    ],
                                ],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0,
                            'maxOutputTokens' => 2048,
                        ],
                    ]);

                $response->throw();

                $answerKey = $this->parseInlineAnswerKey(trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', '')));

                if ($answerKey !== []) {
                    return $answerKey;
                }
            } catch (RequestException $exception) {
                if (in_array($exception->response->status(), [404, 429, 503], true)) {
                    continue;
                }

                throw $exception;
            } catch (ConnectionException) {
                continue;
            }
        }

        return [];
    }

    protected function parseInlineAnswerKey(string $answerKeyText): array
    {
        preg_match_all('/\b(\d{1,3})\s*[:.)-]?\s*([a-e])\b/i', $answerKeyText, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->mapWithKeys(fn (array $match): array => [(int) $match[1] => strtolower($match[2])])
            ->all();
    }

    protected function extractSourceReference(string $text, int $questionPosition): ?string
    {
        $before = substr($text, max(0, $questionPosition - 500), min(500, $questionPosition));
        $lines = collect(preg_split('/\n/', $before) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->reject(fn (string $line): bool => preg_match('/^\d+\s+de\s+\d+$/', $line) === 1)
            ->values();

        return $lines->reverse()
            ->first(fn (string $line): bool => str_contains($line, ' - '))
            ?: null;
    }

    protected function extractSubjectAndTopic(?string $sourceReference, string $raw): array
    {
        $haystack = $sourceReference."\n".$raw;
        $subject = str_contains(Str::ascii($haystack), 'Lingua Portuguesa') ? 'Língua Portuguesa' : null;

        $topic = null;

        if (preg_match('/L[ií]ngua Portuguesa\s*\(Portugu[eê]s\)\s*-\s*([^\n]+)/iu', $haystack, $matches)) {
            $topic = trim($matches[1]);
        }

        return [$subject, $topic];
    }

    protected function cleanText(string $text): string
    {
        $text = preg_replace('/\f/', "\n", $text) ?? $text;
        $text = preg_replace('/^\s*\d+\s+de\s+\d+\s*$/mi', '', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function cleanOptionText(string $text): string
    {
        $text = $this->cleanText($text);
        $parts = preg_split('/\n\s*(?:VUNESP|FGV|CEBRASPE|FCC|IBFC|AOCP|CESGRANRIO)\s+-/iu', $text);

        return trim($parts[0] ?? $text);
    }

    protected function normalizeOptionMarkers(string $text): string
    {
        $text = preg_replace('/(?<!^)(?<!\n)(?<=\S)\s+([a-e])\)\s+/i', "\n$1) ", $text) ?? $text;
        $text = preg_replace('/^\s*([a-e])[\.\-]\s+/mi', '$1) ', $text) ?? $text;

        return $text;
    }
}
