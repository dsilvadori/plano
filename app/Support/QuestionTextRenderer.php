<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class QuestionTextRenderer
{
    protected const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'sub', 'sup'];

    public static function render(?string $text): HtmlString
    {
        if (self::isHtml($text)) {
            return new HtmlString(self::sanitize((string) $text));
        }

        $paragraphs = self::paragraphs((string) $text);

        if ($paragraphs === []) {
            return new HtmlString('');
        }

        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>' . self::formatInline($paragraph) . '</p>')
            ->implode('');

        return new HtmlString($html);
    }

    public static function renderInline(?string $text): HtmlString
    {
        if (self::isHtml($text)) {
            $html = preg_replace('#</p>\s*<p>#i', '<br>', self::sanitize((string) $text)) ?? '';

            return new HtmlString(trim(preg_replace('#</?p>#i', '', $html) ?? $html));
        }

        $text = preg_replace('/[ \t]*\R[ \t]*/u', ' ', trim((string) $text)) ?? (string) $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return new HtmlString(self::formatInline($text));
    }

    public static function renderCommentary(?string $text): HtmlString
    {
        if (self::isHtml($text)) {
            return new HtmlString(self::sanitize((string) $text));
        }

        $paragraphs = self::commentaryParagraphs((string) $text);

        if ($paragraphs === []) {
            return new HtmlString('');
        }

        $html = collect($paragraphs)
            ->map(fn (string $paragraph): string => '<p>' . self::formatInline($paragraph) . '</p>')
            ->implode('');

        return new HtmlString($html);
    }

    /**
     * Converte texto antigo (Markdown simples) em HTML para carregar no editor do admin.
     */
    public static function toEditorHtml(?string $text, bool $commentary = false): string
    {
        if (blank($text) || self::isHtml($text)) {
            return (string) $text;
        }

        return (string) ($commentary ? self::renderCommentary($text) : self::render($text));
    }

    /**
     * Texto para prompts de IA: preserva negrito, sublinhado e destaque como tags.
     */
    public static function toPromptText(?string $text): string
    {
        if (! self::isHtml($text)) {
            return trim((string) $text);
        }

        $html = self::sanitize((string) $text);
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</p>\s*#i', "\n\n", $html) ?? $html;
        $html = strip_tags($html, ['strong', 'b', 'u', 'mark']);

        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function toPlainText(?string $text): string
    {
        $text = self::isHtml($text)
            ? html_entity_decode(strip_tags(preg_replace('#</p>|<br\s*/?>#i', ' ', (string) $text) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : (string) $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function isHtml(?string $text): bool
    {
        return (bool) preg_match('#</?(?:'.implode('|', self::ALLOWED_TAGS).')\b[^>]*>#i', (string) $text);
    }

    protected static function sanitize(string $html): string
    {
        static $sanitizer;

        $sanitizer ??= new HtmlSanitizer(array_reduce(
            self::ALLOWED_TAGS,
            fn (HtmlSanitizerConfig $config, string $tag): HtmlSanitizerConfig => $config->allowElement($tag),
            new HtmlSanitizerConfig(),
        ));

        return $sanitizer->sanitize($html);
    }

    protected static function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($text === '') {
            return [];
        }

        return collect(preg_split("/\n{2,}/", $text) ?: [])
            ->map(fn (string $paragraph): string => preg_replace('/[ \t]*\n[ \t]*/', ' ', trim($paragraph)) ?? $paragraph)
            ->map(fn (string $paragraph): string => preg_replace('/[ \t]{2,}/', ' ', $paragraph) ?? $paragraph)
            ->filter()
            ->values()
            ->all();
    }

    protected static function commentaryParagraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        if ($text === '') {
            return [];
        }

        $text = preg_replace('/[ \t]*\n[ \t]*/', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        $text = preg_replace(
            '/(?<!^)(?<!\w)\s+(?=(?:Alternativa|Letra)?\s*[A-Ea-e]\s*[-).:])/u',
            "\n",
            $text
        ) ?? $text;

        return collect(preg_split('/\n+/', $text) ?: [])
            ->map(fn (string $paragraph): string => trim($paragraph))
            ->filter()
            ->values()
            ->all();
    }

    protected static function formatInline(string $text): string
    {
        $html = e($text);
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/==(.+?)==/s', '<mark>$1</mark>', $html) ?? $html;

        return $html;
    }
}
