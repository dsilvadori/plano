<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class UserSpreadsheetParser
{
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Spreadsheet not found: {$path}");
        }

        $archive = new ZipArchive();

        if ($archive->open($path) !== true) {
            throw new RuntimeException("Unable to open spreadsheet: {$path}");
        }

        try {
            $sharedStrings = $this->readSharedStrings($archive);
            $sheets = $this->readSheets($archive);
            $rows = $sheets
                ->flatMap(fn (array $sheet): array => $this->readWorksheetRows($archive, $sheet['target'], $sharedStrings))
                ->values();

            return $this->parseRows($rows);
        } finally {
            $archive->close();
        }
    }

    protected function parseRows(Collection $rows): array
    {
        $headerColumns = null;
        $totalRows = 0;
        $activeRows = 0;
        $skippedInactiveRows = 0;
        $invalidRows = 0;
        $students = [];

        foreach ($rows as $row) {
            if ($headerColumns === null) {
                $headerColumns = $this->headerColumns($row);

                continue;
            }

            $mapped = $this->mapRow($row, $headerColumns);

            if ($mapped === null) {
                continue;
            }

            $totalRows++;

            if (! $this->isActiveStatus($mapped['status'])) {
                $skippedInactiveRows++;

                continue;
            }

            $email = Str::lower(trim($mapped['email']));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || blank($mapped['name'])) {
                $invalidRows++;

                continue;
            }

            $activeRows++;
            $students[$email] ??= [
                'name' => trim($mapped['name']),
                'email' => $email,
                'courses' => [],
            ];

            if (filled($mapped['course_id']) || filled($mapped['course_name'])) {
                $students[$email]['courses'][] = [
                    'tutory_product_id' => trim($mapped['course_id']),
                    'name' => trim($mapped['course_name']),
                ];
            }
        }

        if ($headerColumns === null) {
            throw new RuntimeException('A planilha precisa conter as colunas E-mail do aluno e Status do Aluno.');
        }

        return [
            'total_rows' => $totalRows,
            'active_rows' => $activeRows,
            'skipped_inactive_rows' => $skippedInactiveRows,
            'invalid_rows' => $invalidRows,
            'students' => array_values($students),
        ];
    }

    protected function headerColumns(array $row): ?Collection
    {
        $normalizedHeaders = collect($row)
            ->mapWithKeys(fn (string $value, string $column): array => [$this->normalizeHeader($value) => $column]);

        if (! $normalizedHeaders->has('email do aluno') || ! $normalizedHeaders->has('status do aluno')) {
            return null;
        }

        return $normalizedHeaders;
    }

    protected function mapRow(array $row, Collection $headerColumns): ?array
    {
        if (! $headerColumns->has('email do aluno') || ! $headerColumns->has('status do aluno')) {
            return null;
        }

        return [
            'course_id' => Arr::get($row, $headerColumns->get('id do curso'), ''),
            'course_name' => Arr::get($row, $headerColumns->get('nome do curso'), ''),
            'email' => Arr::get($row, $headerColumns->get('email do aluno'), ''),
            'name' => Arr::get($row, $headerColumns->get('nome do aluno'), ''),
            'status' => Arr::get($row, $headerColumns->get('status do aluno'), ''),
        ];
    }

    protected function isActiveStatus(string $status): bool
    {
        return Str::of($status)->trim()->lower()->ascii()->value() === 'ativo';
    }

    protected function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->ascii()
            ->replace('e-mail', 'email')
            ->squish()
            ->value();
    }

    protected function readSheets(ZipArchive $archive): Collection
    {
        $workbook = $this->readXml($archive, 'xl/workbook.xml');
        $relationships = $this->readXml($archive, 'xl/_rels/workbook.xml.rels');
        $targetById = collect($relationships->xpath('//*[local-name()="Relationship"]') ?: [])
            ->mapWithKeys(fn (\SimpleXMLElement $relationship): array => [
                (string) $relationship['Id'] => 'xl/' . ltrim((string) $relationship['Target'], '/'),
            ]);

        return collect($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [])
            ->map(function (\SimpleXMLElement $sheet) use ($targetById): array {
                $attributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $relationId = (string) $attributes['id'];

                return [
                    'name' => (string) $sheet['name'],
                    'target' => $targetById[$relationId],
                ];
            });
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
            return (string) ($sharedStrings[(int) ($cell->v ?? 0)] ?? '');
        }

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//*[local-name()="t"]') ?: [];

            return collect($texts)->map(fn (\SimpleXMLElement $text): string => (string) $text)->implode('');
        }

        return isset($cell->v) ? (string) $cell->v : '';
    }

    protected function readXml(ZipArchive $archive, string $path): \SimpleXMLElement
    {
        $contents = $archive->getFromName($path);

        if ($contents === false) {
            throw new RuntimeException("Spreadsheet XML not found: {$path}");
        }

        $xml = simplexml_load_string($contents);

        if ($xml === false) {
            throw new RuntimeException("Invalid spreadsheet XML: {$path}");
        }

        return $xml;
    }
}
