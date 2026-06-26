<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiQuestionCommentaryGenerator
{
    protected ?string $lastModel = null;

    public function lastModel(): ?string
    {
        return $this->lastModel;
    }

    public function generate(Question $question): string
    {
        $apiKey = config('services.gemini.api_key');

        if (blank($apiKey)) {
            throw new RuntimeException('Configure GEMINI_API_KEY para gerar comentários com IA.');
        }

        $question->loadMissing('options');

        $alternatives = $question->options
            ->map(fn ($option): string => strtoupper($option->label).') '.$option->text)
            ->join("\n");

        $prompt = implode("\n\n", [
            'Você é um professor de concursos públicos.',
            'Gere um comentário explicativo em português do Brasil para a questão abaixo.',
            'Comece indicando o gabarito informado e explique por que essa alternativa está correta.',
            'Depois, quando houver informação suficiente no enunciado e nas alternativas, explique brevemente por que as alternativas incorretas estão erradas.',
            'Seja claro, didático e objetivo, com linguagem adequada para estudante de concurso público.',
            'Não invente fatos, normas, conceitos ou contexto que não estejam no enunciado, nas alternativas ou no conhecimento gramatical/jurídico/técnico necessário para resolver a questão.',
            'Use no máximo 3 parágrafos curtos.',
            'Pode usar Markdown para destacar termos importantes com **negrito**.',
            'Se a questão estiver incompleta ou o gabarito não estiver claro, diga que é necessário revisar o gabarito antes de comentar.',
            'Enunciado:',
            $question->statement,
            'Alternativas:',
            $alternatives,
            'Gabarito informado: '.($question->answer_key ? strtoupper($question->answer_key) : 'não informado'),
        ]);

        $lastException = null;

        foreach ($this->modelsToTry() as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('services.gemini.base_url'), '/'))
                    ->acceptJson()
                    ->timeout(45)
                    ->post("/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [[
                            'parts' => [[
                                'text' => $prompt,
                            ]],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 900,
                        ],
                    ]);

                $response->throw();

                $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

                if ($text === '') {
                    throw new RuntimeException('A IA não retornou um comentário para esta questão.');
                }

                $this->lastModel = $model;

                return $text;
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

        throw new RuntimeException('Nenhum modelo do Gemini está disponível para gerar o comentário agora.');
    }

    /**
     * @return array<int, string>
     */
    protected function modelsToTry(): array
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
}
