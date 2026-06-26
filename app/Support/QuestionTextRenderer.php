<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

class QuestionTextRenderer
{
    public static function render(?string $text): HtmlString
    {
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
        $text = preg_replace('/[ \t]*\R[ \t]*/u', ' ', trim((string) $text)) ?? (string) $text;
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;

        return new HtmlString(self::formatInline($text));
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

    protected static function formatInline(string $text): string
    {
        $html = e($text);
        $html = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $html) ?? $html;
        $html = preg_replace('/==(.+?)==/s', '<mark>$1</mark>', $html) ?? $html;

        return $html;
    }
}
