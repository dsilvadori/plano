<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class CourseSpreadsheetParser
{
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Spreadsheet not found: {$path}");
        }

        if (Str::lower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            return $this->parseCsv($path);
        }

        $archive = new ZipArchive();

        if ($archive->open($path) !== true) {
            throw new RuntimeException("Unable to open spreadsheet: {$path}");
        }

        try {
            $sharedStrings = $this->readSharedStrings($archive);
            $sheets = $this->readSheets($archive);
            $courseName = $this->resolveCourseName($archive, $sharedStrings, $sheets, $path);

            $importableSheets = collect($sheets)
                ->reject(fn (array $sheet) => Str::lower($sheet['name']) === 'nome do curso')
                ->values();

            $modules = $this->parseModules($archive, $sharedStrings, $importableSheets);

            return [
                'course_name' => $courseName,
                'course_slug' => Str::slug($courseName),
                'study_track_name' => 'Trilha Oficial - ' . $courseName,
                'modules' => $modules,
            ];
        } finally {
            $archive->close();
        }
    }

    protected function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV: {$path}");
        }

        try {
            $headers = null;
            $rows = [];

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) === 1 && str_contains((string) $row[0], ',')) {
                    $row = str_getcsv((string) $row[0], ',');
                }

                if ($headers === null) {
                    $headers = collect($row)
                        ->map(fn (string $header) => $this->normalizeHeader($header))
                        ->all();

                    continue;
                }

                $mapped = [];

                foreach ($headers as $index => $header) {
                    $mapped[$header] = trim((string) ($row[$index] ?? ''));
                }

                if (collect($mapped)->filter()->isNotEmpty()) {
                    $rows[] = $mapped;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new RuntimeException('O CSV não possui linhas importáveis.');
        }

        $firstRow = $rows[0];
        $courseName = $firstRow['course_name'] ?? $firstRow['curso'] ?? $this->fallbackCourseNameFromPath($path);
        $groupedRows = collect($rows)
            ->filter(fn (array $row) => filled($row['module_name'] ?? $row['modulo'] ?? null) && filled($row['lesson_title'] ?? $row['aula'] ?? null))
            ->groupBy(fn (array $row) => $row['module_name'] ?? $row['modulo']);

        if ($groupedRows->isEmpty()) {
            throw new RuntimeException('O CSV precisa conter as colunas module_name e lesson_title.');
        }

        $sortOrder = 1;
        $modules = $groupedRows
            ->map(function (Collection $moduleRows, string $moduleName) use (&$sortOrder) {
                $first = $moduleRows->first();
                $moduleSortOrder = (int) ($first['module_sort_order'] ?? $first['ordem_modulo'] ?? 0);
                $lessons = $moduleRows
                    ->values()
                    ->map(function (array $row, int $index) {
                        $minutes = (int) round((float) str_replace(',', '.', $row['lesson_minutes'] ?? $row['minutos'] ?? 0));

                        return [
                            'name' => $this->normalizeLessonName($row['lesson_title'] ?? $row['aula']),
                            'minutes' => max(0, $minutes),
                            'type' => $row['lesson_type'] ?? $row['tipo_aula'] ?? 'video',
                            'status' => $row['lesson_status'] ?? $row['status_aula'] ?? 'published',
                            'thumbnail_url' => $row['thumbnail_url'] ?? null,
                            'panda_video_id' => $row['panda_video_id'] ?? null,
                            'panda_embed_url' => $row['panda_embed_url'] ?? null,
                            'panda_player_url' => $row['panda_player_url'] ?? null,
                            'sort_order' => (int) ($row['lesson_sort_order'] ?? $row['ordem_aula'] ?? ($index + 1)),
                        ];
                    })
                    ->filter(fn (array $lesson) => filled($lesson['name']) && $lesson['minutes'] > 0)
                    ->values()
                    ->all();

                $assignedSortOrder = $moduleSortOrder ?: $sortOrder++;

                return [
                    'sheet_name' => 'CSV',
                    'group_name' => $first['module_group'] ?? $first['grupo_modulo'] ?? 'CSV',
                    'track_name' => $moduleName,
                    'name' => $moduleName,
                    'type' => $first['module_type'] ?? $first['tipo_modulo'] ?? $this->inferModuleType('CSV', null, $moduleName),
                    'workload_minutes' => array_sum(array_column($lessons, 'minutes')),
                    'sort_order' => $assignedSortOrder,
                    'lessons' => $lessons,
                ];
            })
            ->filter(fn (array $module) => $module['lessons'] !== [])
            ->sortBy('sort_order')
            ->values()
            ->map(function (array $module, int $index) {
                $module['sort_order'] = $module['sort_order'] ?: ($index + 1);

                return $module;
            })
            ->all();

        if ($modules === []) {
            throw new RuntimeException('O CSV não possui aulas com minutos válidos.');
        }

        return [
            'course_name' => trim($courseName),
            'course_slug' => Str::slug($courseName),
            'study_track_name' => 'Trilha Oficial - ' . trim($courseName),
            'modules' => $modules,
        ];
    }

    protected function parseModules(ZipArchive $archive, array $sharedStrings, Collection $sheets): array
    {
        $sortOrder = 1;

        return $sheets
            ->flatMap(function (array $sheet) use ($archive, $sharedStrings, &$sortOrder) {
                $rows = $this->readWorksheetRows($archive, $sheet['target'], $sharedStrings);
                $groupName = null;
                $currentTrackName = null;
                $currentLessons = [];
                $modules = [];

                $flushTrack = function () use (&$modules, &$currentTrackName, &$currentLessons, &$sortOrder, &$groupName, $sheet): void {
                    if (blank($currentTrackName) || empty($currentLessons)) {
                        $currentTrackName = null;
                        $currentLessons = [];

                        return;
                    }

                    $moduleName = trim(($groupName ? $groupName . ' - ' : '') . $currentTrackName);

                    $modules[] = [
                        'sheet_name' => $sheet['name'],
                        'group_name' => $groupName ?: $sheet['name'],
                        'track_name' => $currentTrackName,
                        'name' => $moduleName,
                        'type' => $this->inferModuleType($sheet['name'], $groupName, $currentTrackName),
                        'workload_minutes' => array_sum(array_column($currentLessons, 'minutes')),
                        'sort_order' => $sortOrder++,
                        'lessons' => $currentLessons,
                    ];

                    $currentTrackName = null;
                    $currentLessons = [];
                };

                foreach ($rows as $row) {
                    $firstCell = trim((string) Arr::get($row, 'A', ''));
                    $minutesCell = trim((string) Arr::get($row, 'B', ''));

                    if ($firstCell === '') {
                        $flushTrack();

                        continue;
                    }

                    if (Str::startsWith($firstCell, 'Módulo - ')) {
                        $groupName = trim(Str::after($firstCell, 'Módulo - '));

                        continue;
                    }

                    if (Str::startsWith($firstCell, 'Trilha - ')) {
                        $flushTrack();
                        $currentTrackName = trim(Str::after($firstCell, 'Trilha - '));

                        continue;
                    }

                    if ($this->isHeaderRow($firstCell)) {
                        continue;
                    }

                    if ($currentTrackName === null) {
                        $groupName ??= $this->normalizeSheetName($sheet['name']);
                        $currentTrackName = $groupName;
                    }

                    if (! is_numeric($minutesCell)) {
                        continue;
                    }

                    $currentLessons[] = [
                        'name' => $this->normalizeLessonName($firstCell),
                        'minutes' => (int) round((float) $minutesCell),
                    ];
                }

                $flushTrack();

                return $modules;
            })
            ->all();
    }

    protected function isHeaderRow(string $value): bool
    {
        return in_array(Str::lower(trim($value)), [
            'horas aulas',
            'aula',
        ], true);
    }

    protected function resolveCourseName(ZipArchive $archive, array $sharedStrings, Collection $sheets, string $path): string
    {
        $courseSheet = $sheets->first(fn (array $sheet) => Str::lower($sheet['name']) === 'nome do curso');

        if ($courseSheet) {
            $rows = $this->readWorksheetRows($archive, $courseSheet['target'], $sharedStrings);
            $value = trim((string) Arr::get($rows, '0.A', ''));

            if ($value !== '') {
                return $value;
            }
        }

        return $this->fallbackCourseNameFromPath($path);
    }

    protected function fallbackCourseNameFromPath(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/\s*\(\d+\)$/', '', $name) ?: $name;

        return trim($name);
    }

    protected function inferModuleType(string $sheetName, ?string $groupName, string $trackName): string
    {
        $context = Str::of(implode(' ', array_filter([$sheetName, $groupName, $trackName])))
            ->lower()
            ->ascii()
            ->value();

        if (Str::contains($context, ['questao', 'questoes', 'caderno'])) {
            return 'questions';
        }

        if (Str::contains($context, ['complementar', 'complementares'])) {
            return 'complementary';
        }

        if (Str::contains($context, ['redacao oficial', 'arquivologia', 'atendimento', 'licitacao', 'direito adm', 'legislacao', 'estatuto', 'lei organica'])) {
            return 'specific';
        }

        return 'basic';
    }

    protected function normalizeSheetName(string $sheetName): string
    {
        return trim(preg_replace('/^(ce|c\.e)\s*-\s*/iu', '', $sheetName) ?? $sheetName);
    }

    protected function normalizeLessonName(string $value): string
    {
        return trim(str_replace("\n", ' ', preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    protected function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->trim()
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    protected function readSheets(ZipArchive $archive): Collection
    {
        $workbook = $this->readXml($archive, 'xl/workbook.xml');
        $relationships = $this->readXml($archive, 'xl/_rels/workbook.xml.rels');
        $namespaces = [
            'main' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
            'rel' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'pkg' => 'http://schemas.openxmlformats.org/package/2006/relationships',
        ];

        $sheetNodes = $workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') ?: [];
        $relationshipNodes = $relationships->xpath('//*[local-name()="Relationship"]') ?: [];
        $targetById = collect($relationshipNodes)->mapWithKeys(
            fn (\SimpleXMLElement $relationship) => [
                (string) $relationship['Id'] => 'xl/' . ltrim((string) $relationship['Target'], '/'),
            ]
        );

        return collect($sheetNodes)->map(function (\SimpleXMLElement $sheet) use ($targetById, $namespaces) {
            $attributes = $sheet->attributes($namespaces['rel']);
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

        $xml = $this->readXml($archive, 'xl/sharedStrings.xml');
        $nodes = $xml->xpath('//*[local-name()="si"]') ?: [];

        return collect($nodes)->map(function (\SimpleXMLElement $node) {
            $texts = $node->xpath('.//*[local-name()="t"]') ?: [];

            return collect($texts)->map(fn (\SimpleXMLElement $text) => (string) $text)->implode('');
        })->all();
    }

    protected function readWorksheetRows(ZipArchive $archive, string $worksheetPath, array $sharedStrings): array
    {
        $worksheet = $this->readXml($archive, $worksheetPath);
        $rows = $worksheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];

        return collect($rows)->map(function (\SimpleXMLElement $row) use ($sharedStrings) {
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
            $index = (int) ($cell->v ?? 0);

            return (string) ($sharedStrings[$index] ?? '');
        }

        if ($type === 'inlineStr') {
            $texts = $cell->xpath('.//*[local-name()="t"]') ?: [];

            return collect($texts)->map(fn (\SimpleXMLElement $text) => (string) $text)->implode('');
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
