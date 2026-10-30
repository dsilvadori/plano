<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionImportBatch;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class QuestionSpreadsheetImporter
{
    public function import(QuestionBank $bank, string $path, ?int $userId = null): QuestionImportBatch
    {
        return $this->importParsedQuestions($bank, $this->parse($path), $userId, 'xlsx', $bank->source_file_path);
    }

    public function importParsedQuestions(QuestionBank $bank, array $parsedQuestions, ?int $userId = null, string $sourceType = 'xlsx', ?string $filePath = null): QuestionImportBatch
    {
        $batch = QuestionImportBatch::query()->create([
            'course_id' => null,
            'question_bank_id' => $bank->id,
            'created_by' => $userId,
            'source_type' => $sourceType,
            'file_path' => $filePath,
            'status' => 'extracting',
        ]);

        DB::transaction(function () use ($bank, $batch, $parsedQuestions): void {
            $removedQuestions = $bank->questions()->count();
            $bank->questions()->delete();

            foreach ($parsedQuestions as $parsedQuestion) {
                $question = Question::query()->create([
                    'question_bank_id' => $bank->id,
                    'course_id' => null,
                    'number' => $parsedQuestion['number'],
                    'subject' => $parsedQuestion['subject'],
                    'topic' => $parsedQuestion['topic'],
                    'subtopic' => $parsedQuestion['subtopic'],
                    'statement' => $parsedQuestion['statement'],
                    'type' => 'multiple_choice',
                    'answer_key' => $parsedQuestion['answer_key'],
                    'commentary' => $parsedQuestion['commentary'],
                    'commentary_provider' => filled($parsedQuestion['commentary']) ? $batch->source_type : null,
                    'source_reference' => $parsedQuestion['source_reference'],
                    'status' => $parsedQuestion['answer_key'] ? 'published' : 'review',
                    'metadata' => [
                        'import_batch_id' => $batch->id,
                        'observacoes_revisao' => $parsedQuestion['review_notes'],
                        'image_urls' => $parsedQuestion['image_urls'] ?? [],
                        'image_description' => $parsedQuestion['image_description'] ?? null,
                    ],
                ]);

                foreach ($this->normalizeOptions($parsedQuestion['options']) as $sortOrder => $option) {
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
                    'source' => $batch->source_type,
                    'removed_questions' => $removedQuestions,
                    'without_answer_key' => collect($parsedQuestions)->whereNull('answer_key')->count(),
                    'imported_numbers' => collect($parsedQuestions)->pluck('number')->all(),
                ],
            ])->save();

            $bank->forceFill([
                'course_id' => null,
                'source_type' => $batch->source_type,
                'status' => collect($parsedQuestions)->contains(fn (array $question): bool => filled($question['answer_key'])) ? 'published' : 'draft',
                'metadata' => array_replace($bank->metadata ?? [], [
                    'last_import_batch_id' => $batch->id,
                    'last_imported_at' => now()->toIso8601String(),
                    'questions_imported' => count($parsedQuestions),
                    'last_import_source' => $batch->source_type,
                ]),
            ])->save();
        });

        return $batch->fresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Planilha não encontrada: {$path}");
        }

        $archive = new ZipArchive();

        if ($archive->open($path) !== true) {
            throw new RuntimeException("Não foi possível abrir a planilha: {$path}");
        }

        try {
            $sharedStrings = $this->readSharedStrings($archive);
            $sheets = $this->readSheets($archive);
            $questions = [];

            foreach ($sheets as $sheet) {
                $rows = $this->readWorksheetRows($archive, $sheet['target'], $sharedStrings);
                $questions = array_merge($questions, $this->parseRows($rows));
            }
        } finally {
            $archive->close();
        }

        $questions = collect($questions)
            ->unique('number')
            ->sortBy('number')
            ->values()
            ->all();

        if ($questions === []) {
            throw new RuntimeException('A planilha não possui questões importáveis. Confira as colunas numero, enunciado e alternativas.');
        }

        return $questions;
    }

    /**
     * @param  array<int, array{label: string, text: string}>  $options
     * @return array<int, array{label: string, text: string}>
     */
    protected function normalizeOptions(array $options): array
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

    /**
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function parseRows(array $rows): array
    {
        $headers = null;
        $questions = [];

        foreach ($rows as $row) {
            if ($headers === null) {
                $candidate = $this->headerColumns($row);

                if ($candidate !== null) {
                    $headers = $candidate;
                }

                continue;
            }

            $mapped = $this->mapRow($row, $headers);

            if ($mapped === null) {
                continue;
            }

            $questions[] = $mapped;
        }

        return $questions;
    }

    protected function headerColumns(array $row): ?array
    {
        $headers = collect($row)
            ->mapWithKeys(fn (string $value, string $column): array => [$this->normalizeHeader($value) => $column])
            ->filter(fn (string $column, string $header): bool => $header !== '')
            ->all();

        if (! isset($headers['numero'], $headers['enunciado'])) {
            return null;
        }

        return $headers;
    }

    protected function mapRow(array $row, array $headers): ?array
    {
        $number = (int) $this->cell($row, $headers, ['numero', 'n', 'questao', 'questao numero']);
        $statement = $this->cell($row, $headers, ['enunciado', 'pergunta', 'statement']);

        if ($number <= 0 || blank($statement)) {
            return null;
        }

        $options = collect(['a', 'b', 'c', 'd', 'e'])
            ->map(function (string $label) use ($row, $headers): ?array {
                $text = $this->cell($row, $headers, ["alternativa {$label}", "alternativa_{$label}", $label]);

                if (blank($text)) {
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

        return [
            'number' => $number,
            'subject' => $this->cell($row, $headers, ['disciplina', 'materia']),
            'topic' => $this->cell($row, $headers, ['assunto', 'topico']),
            'subtopic' => $this->cell($row, $headers, ['subassunto', 'subtopico']),
            'statement' => $statement,
            'options' => $options,
            'answer_key' => $this->normalizeAnswerKey($this->cell($row, $headers, ['gabarito', 'resposta', 'answer_key'])),
            'commentary' => $this->cell($row, $headers, ['comentario', 'comentario explicativo', 'explicacao']),
            'source_reference' => $this->cell($row, $headers, ['referencia origem', 'referencia_origem', 'origem']),
            'review_notes' => $this->cell($row, $headers, ['observacoes revisao', 'observacoes_revisao', 'observacoes']),
            'image_urls' => $this->imageUrlsFromRow($row, $headers),
            'image_description' => $this->cell($row, $headers, ['imagem descricao', 'imagem_descricao', 'descricao imagem', 'descricao_imagem']),
        ];
    }

    protected function imageUrlsFromRow(array $row, array $headers): array
    {
        $values = [
            $this->cell($row, $headers, ['imagem', 'imagem url', 'imagem_url', 'image', 'image url', 'image_url']),
            $this->cell($row, $headers, ['imagem enunciado', 'imagem_enunciado', 'statement image', 'statement_image']),
            $this->cell($row, $headers, ['imagem 1', 'imagem_1', 'image 1', 'image_1']),
            $this->cell($row, $headers, ['imagem 2', 'imagem_2', 'image 2', 'image_2']),
            $this->cell($row, $headers, ['imagem 3', 'imagem_3', 'image 3', 'image_3']),
        ];

        return collect($values)
            ->filter()
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

    protected function cell(array $row, array $headers, array $keys): ?string
    {
        foreach ($keys as $key) {
            $column = $headers[$this->normalizeHeader($key)] ?? null;

            if ($column !== null) {
                $value = trim((string) Arr::get($row, $column, ''));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    protected function normalizeAnswerKey(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = Str::of($value)->trim()->lower()->ascii()->value();

        return in_array($normalized, ['a', 'b', 'c', 'd', 'e'], true) ? $normalized : null;
    }

    protected function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->ascii()
            ->replace(['-', '.'], ' ')
            ->replace('_', ' ')
            ->squish()
            ->value();
    }

    protected function readSheets(ZipArchive $archive): array
    {
        $workbook = $this->readXml($archive, 'xl/workbook.xml');
        $relationships = $this->readXml($archive, 'xl/_rels/workbook.xml.rels');
        $targetById = collect($relationships->xpath('//*[local-name()="Relationship"]') ?: [])
            ->mapWithKeys(fn (\SimpleXMLElement $relationship): array => [
                (string) $relationship['Id'] => $this->normalizeWorkbookRelationshipTarget((string) $relationship['Target']),
            ]);

        return collect($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [])
            ->map(function (\SimpleXMLElement $sheet) use ($targetById): array {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationId = (string) $attributes['id'];

                return [
                    'name' => (string) $sheet['name'],
                    'target' => $targetById[$relationId],
                ];
            })
            ->all();
    }

    protected function normalizeWorkbookRelationshipTarget(string $target): string
    {
        $target = ltrim($target, '/');

        return Str::startsWith($target, 'xl/')
            ? $target
            : 'xl/' . $target;
    }

    protected function readSharedStrings(ZipArchive $archive): array
    {
        if ($archive->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $nodes = $this->readXml($archive, 'xl/sharedStrings.xml')->xpath('//*[local-name()="si"]') ?: [];

        return collect($nodes)
            ->map(function (\SimpleXMLElement $node): string {
                $texts = $node->xpath('.//*[local-name()="t"]') ?: [];

                return collect($texts)->map(fn (\SimpleXMLElement $text): string => (string) $text)->implode('');
            })
            ->all();
    }

    protected function readWorksheetRows(ZipArchive $archive, string $worksheetPath, array $sharedStrings): array
    {
        $rows = $this->readXml($archive, $worksheetPath)->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];

        return collect($rows)->map(function (\SimpleXMLElement $row) use ($sharedStrings): array {
            $values = [];
            $cells = $row->xpath('./*[local-name()="c"]') ?: [];

            foreach ($cells as $cell) {
                $reference = (string) $cell['r'];
                $column = preg_replace('/\d+/', '', $reference) ?: $reference;
                $values[$column] = $this->readCellValue($cell, $sharedStrings);
            }

            return $values;
        })->all();
    }

    protected function readCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            return (string) ($sharedStrings[(int) ($this->cellRawValue($cell) ?? 0)] ?? '');
        }

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//*[local-name()="t"]') ?: [];

            return collect($texts)->map(fn (\SimpleXMLElement $text): string => (string) $text)->implode('');
        }

        return $this->cellRawValue($cell) ?? '';
    }

    protected function cellRawValue(\SimpleXMLElement $cell): ?string
    {
        $values = $cell->xpath('./*[local-name()="v"]') ?: [];

        if ($values === []) {
            return null;
        }

        return (string) $values[0];
    }

    protected function readXml(ZipArchive $archive, string $path): \SimpleXMLElement
    {
        $contents = $archive->getFromName($path);

        if ($contents === false) {
            throw new RuntimeException("XML da planilha não encontrado: {$path}");
        }

        $xml = simplexml_load_string($contents);

        if ($xml === false) {
            throw new RuntimeException("XML inválido na planilha: {$path}");
        }

        return $xml;
    }
}
