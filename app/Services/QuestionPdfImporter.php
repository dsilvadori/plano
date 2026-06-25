<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionImportBatch;
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

        $batch = QuestionImportBatch::query()->create([
            'course_id' => $bank->course_id,
            'question_bank_id' => $bank->id,
            'created_by' => $userId,
            'source_type' => 'pdf',
            'file_path' => $bank->source_file_path,
            'status' => 'extracting',
        ]);

        try {
            $text = $this->extractText($pdfPath);
            $parsedQuestions = $this->parseText($text);

            DB::transaction(function () use ($bank, $batch, $parsedQuestions, $text): void {
                foreach ($parsedQuestions as $parsedQuestion) {
                    $question = Question::query()->updateOrCreate([
                        'question_bank_id' => $bank->id,
                        'number' => $parsedQuestion['number'],
                    ], [
                        'course_id' => $bank->course_id,
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

                    $question->options()->delete();

                    foreach ($parsedQuestion['options'] as $sortOrder => $option) {
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
                    ],
                ])->save();

                $bank->forceFill([
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
            'Preserve também a seção de gabarito, se existir.',
            'Retorne apenas o texto extraído, sem comentários adicionais.',
        ]);
        $lastException = null;

        foreach ($this->geminiModelsToTry() as $model) {
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
                            'maxOutputTokens' => 16000,
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
    protected function geminiModelsToTry(): array
    {
        $configuredModel = trim((string) config('services.gemini.model'), '/');

        return collect([
            $configuredModel,
            'gemini-2.5-flash-lite',
            'gemini-3.5-flash',
            'gemini-2.5-flash',
            'gemini-flash-latest',
        ])
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
}
