<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Str;

class LessonSummaryPdfGenerator
{
    protected array $objects = [];

    protected int $fontRegularObject = 0;

    protected int $fontBoldObject = 0;

    protected ?int $logoObject = null;

    protected ?array $logo = null;

    protected float $pageWidth = 595.28;

    protected float $pageHeight = 841.89;

    protected float $margin = 54;

    public function generate(Course $course, Lesson $lesson, string $summary): string
    {
        $this->objects = [];
        $this->fontRegularObject = 0;
        $this->fontBoldObject = 0;
        $this->logoObject = null;
        $this->logo = $this->loadLogo();

        $pages = $this->buildPages($course, $lesson, $summary);

        return $this->renderPdf($pages);
    }

    protected function buildPages(Course $course, Lesson $lesson, string $summary): array
    {
        $pages = [];
        $current = $this->newContent();

        $this->drawCover($current, $course, $lesson);
        $this->drawFooter($current, 1);
        $pages[] = $current;

        $current = $this->newContent();
        $this->drawPageHeader($current, $course, $lesson);
        $y = $this->pageHeight - 124;

        foreach ($this->summaryBlocks($summary) as $block) {
            $spacing = $block['type'] === 'heading' ? 18 : 8;
            $fontSize = $block['type'] === 'heading' ? 15 : 11;
            $lineHeight = $block['type'] === 'heading' ? 19 : 16;
            $maxWidth = $this->pageWidth - ($this->margin * 2);
            $prefix = $block['type'] === 'list' ? '- ' : '';
            $lines = $this->wrapText($prefix.$block['text'], $maxWidth, $fontSize);
            $needed = $spacing + (count($lines) * $lineHeight) + ($block['type'] === 'heading' ? 8 : 4);

            if ($y - $needed < 72) {
                $this->drawFooter($current, count($pages) + 1);
                $pages[] = $current;
                $current = $this->newContent();
                $this->drawPageHeader($current, $course, $lesson);
                $y = $this->pageHeight - 124;
            }

            $y -= $spacing;

            if ($block['type'] === 'heading') {
                $this->setFillColor($current, 9, 22, 48);
                foreach ($lines as $line) {
                    $this->text($current, $this->margin, $y, $line, 16, true);
                    $y -= $lineHeight;
                }

                $y -= 2;
                $this->setFillColor($current, 246, 190, 28);
                $this->rect($current, $this->margin, $y, 54, 2, 'f');
                $y -= 8;
            } else {
                $this->setFillColor($current, 39, 46, 68);

                foreach ($lines as $line) {
                    $this->text($current, $this->margin, $y, $line, 11);
                    $y -= $lineHeight;
                }
            }
        }

        $this->drawFooter($current, count($pages) + 1);
        $pages[] = $current;

        return $pages;
    }

    protected function drawCover(string &$content, Course $course, Lesson $lesson): void
    {
        $this->setFillColor($content, 248, 250, 252);
        $this->rect($content, 0, 0, $this->pageWidth, $this->pageHeight, 'f');

        $this->setFillColor($content, 7, 12, 28);
        $this->rect($content, 0, $this->pageHeight - 190, $this->pageWidth, 190, 'f');
        $this->setFillColor($content, 246, 190, 28);
        $this->rect($content, 0, $this->pageHeight - 194, $this->pageWidth, 4, 'f');

        $this->drawLogo($content, $this->margin, $this->pageHeight - 145, 104);

        $this->setFillColor($content, 246, 190, 28);
        $this->text($content, $this->margin, 590, 'RESUMO DA AULA', 12, true);

        $this->setFillColor($content, 9, 22, 48);
        $this->multiline($content, $this->margin, 548, $lesson->title, 28, 34, true, 470);

        $this->setFillColor($content, 71, 85, 105);
        $this->multiline($content, $this->margin, 450, $course->name, 13, 18, false, 440);

        $this->setFillColor($content, 255, 255, 255);
        $this->rect($content, $this->margin, 270, 230, 102, 'f');
        $this->setFillColor($content, 246, 190, 28);
        $this->rect($content, $this->margin, 368, 230, 4, 'f');
        $this->setFillColor($content, 9, 22, 48);
        $this->text($content, $this->margin + 22, 334, 'Plataforma Vencendo Concursos', 12, true);
        $this->setFillColor($content, 71, 85, 105);
        $this->multiline($content, $this->margin + 22, 310, 'Material de apoio para revisão, memorização e retomada rápida do conteúdo.', 10, 15, false, 180);

        $this->setFillColor($content, 226, 232, 240);
        $this->rect($content, 328, 270, 154, 102, 'f');
        $this->setFillColor($content, 30, 64, 120);
        $this->rect($content, 354, 327, 102, 9, 'f');
        $this->rect($content, 354, 305, 76, 9, 'f');
        $this->rect($content, 354, 283, 118, 9, 'f');
    }

    protected function drawPageHeader(string &$content, Course $course, Lesson $lesson): void
    {
        $this->setFillColor($content, 255, 255, 255);
        $this->rect($content, 0, 0, $this->pageWidth, $this->pageHeight, 'f');
        $this->setFillColor($content, 7, 12, 28);
        $this->rect($content, 0, $this->pageHeight - 70, $this->pageWidth, 70, 'f');
        $this->setFillColor($content, 246, 190, 28);
        $this->rect($content, 0, $this->pageHeight - 73, $this->pageWidth, 3, 'f');

        $this->drawLogo($content, $this->margin, $this->pageHeight - 55, 76);

        $this->setFillColor($content, 255, 255, 255);
        $this->text($content, 170, $this->pageHeight - 31, Str::limit($lesson->title, 58), 11, true);
        $this->setFillColor($content, 196, 205, 222);
        $this->text($content, 170, $this->pageHeight - 49, Str::limit($course->name, 64), 9);
    }

    protected function drawFooter(string &$content, int $pageNumber): void
    {
        $this->setFillColor($content, 137, 148, 170);
        $this->text($content, $this->margin, 40, 'Plataforma Vencendo Concursos', 8);
        $this->text($content, $this->pageWidth - $this->margin - 44, 40, 'Página '.$pageNumber, 8);
    }

    protected function summaryBlocks(string $summary): array
    {
        $summary = trim(str_replace('```', '', $summary));
        $blocks = [];

        foreach (preg_split('/\R/', $summary) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\*\*(.+?)\*\*/u', '$1', $line) ?? $line;

            if (preg_match('/^#{1,4}\s+(.+)$/u', $line, $matches)) {
                $blocks[] = ['type' => 'heading', 'text' => trim($matches[1])];

                continue;
            }

            if (str_starts_with($line, '- ')) {
                $blocks[] = ['type' => 'list', 'text' => trim(substr($line, 2))];

                continue;
            }

            $blocks[] = ['type' => 'paragraph', 'text' => $line];
        }

        return $blocks;
    }

    protected function renderPdf(array $pages): string
    {
        $this->fontRegularObject = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->fontBoldObject = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');

        if ($this->logo) {
            $stream = $this->binaryStream($this->logo['data']);
            $this->logoObject = $this->addObject("<< /Type /XObject /Subtype /Image /Width {$this->logo['width']} /Height {$this->logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($this->logo['data'])." >>\nstream\n{$stream}\nendstream");
        }

        $pageObjectIds = [];

        foreach ($pages as $page) {
            $contentObject = $this->addObject("<< /Length ".strlen($page)." >>\nstream\n{$page}\nendstream");
            $pageObjectIds[] = $this->addObject($this->pageObject($contentObject));
        }

        $kids = collect($pageObjectIds)->map(fn (int $id): string => "{$id} 0 R")->join(' ');
        $pagesObject = $this->addObject("<< /Type /Pages /Kids [{$kids}] /Count ".count($pageObjectIds).' >>');
        $catalogObject = $this->addObject("<< /Type /Catalog /Pages {$pagesObject} 0 R >>");

        return $this->assemblePdf($catalogObject);
    }

    protected function pageObject(int $contentObject): string
    {
        $xObjects = $this->logoObject ? " /XObject << /ImLogo {$this->logoObject} 0 R >>" : '';

        return "<< /Type /Page /Parent __PAGES__ 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] /Resources << /Font << /F1 {$this->fontRegularObject} 0 R /F2 {$this->fontBoldObject} 0 R >>{$xObjects} >> /Contents {$contentObject} 0 R >>";
    }

    protected function assemblePdf(int $catalogObject): string
    {
        $pagesObject = count($this->objects) - 1;
        $objects = array_map(fn (string $object): string => str_replace('__PAGES__', (string) $pagesObject, $object), $this->objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $id = $index + 1;
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root {$catalogObject} 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    protected function addObject(string $content): int
    {
        $this->objects[] = $content;

        return count($this->objects);
    }

    protected function newContent(): string
    {
        return '';
    }

    protected function text(string &$content, float $x, float $y, string $text, int $size = 11, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $content .= "BT /{$font} {$size} Tf 1 0 0 1 {$x} {$y} Tm ({$this->escapeText($text)}) Tj ET\n";
    }

    protected function multiline(string &$content, float $x, float $y, string $text, int $size, int $lineHeight, bool $bold, float $width): float
    {
        foreach ($this->wrapText($text, $width, $size) as $line) {
            $this->text($content, $x, $y, $line, $size, $bold);
            $y -= $lineHeight;
        }

        return $y;
    }

    protected function wrapText(string $text, float $width, int $fontSize): array
    {
        $maxChars = max(20, (int) floor($width / ($fontSize * 0.52)));
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';

        foreach ($words as $word) {
            $candidate = trim($line.' '.$word);

            if (mb_strlen($candidate) > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    protected function rect(string &$content, float $x, float $y, float $width, float $height, string $mode): void
    {
        $content .= "{$x} {$y} {$width} {$height} re {$mode}\n";
    }

    protected function roundedCard(string &$content, float $x, float $y, float $width, float $height): void
    {
        $this->rect($content, $x, $y, $width, $height, 'f');
    }

    protected function setFillColor(string &$content, int $red, int $green, int $blue): void
    {
        $content .= sprintf("%.3F %.3F %.3F rg\n", $red / 255, $green / 255, $blue / 255);
    }

    protected function drawLogo(string &$content, float $x, float $y, float $width): void
    {
        if ($this->logo) {
            $height = $width * ($this->logo['height'] / $this->logo['width']);
            $content .= "q {$width} 0 0 {$height} {$x} {$y} cm /ImLogo Do Q\n";

            return;
        }

        $this->setFillColor($content, 255, 255, 255);
        $this->text($content, $x, $y + 30, 'VENCENDO', 14, true);
        $this->text($content, $x, $y + 13, 'CONCURSOS', 14, true);
    }

    protected function loadLogo(): ?array
    {
        $path = public_path('images/vencendo-concursos-logo-white.webp');

        if (! is_file($path) || ! function_exists('imagecreatefromwebp')) {
            return null;
        }

        $image = @imagecreatefromwebp($path);

        if (! $image) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 90);
        $data = ob_get_clean();
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);

        return $data ? compact('data', 'width', 'height') : null;
    }

    protected function escapeText(string $text): string
    {
        $text = str_replace(
            ['“', '”', '‘', '’', '–', '—', '…', "\u{00A0}"],
            ['"', '"', "'", "'", '-', '-', '...', ' '],
            $text,
        );
        $text = iconv('UTF-8', 'Windows-1252//IGNORE', $text) ?: $text;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    protected function binaryStream(string $data): string
    {
        return $data;
    }
}
