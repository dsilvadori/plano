<?php

namespace App\Support;

class ThumbnailUrl
{
    public const FALLBACK = 'https://vencendoconcursos.com.br/wp-content/uploads/2026/04/logo-vc-transparente.png';

    public static function fromPathOrUrl(?string $path, ?string $url = null): string
    {
        if (filled($path)) {
            return url('/media/thumbnails/' . ltrim($path, '/'));
        }

        return filled($url) ? $url : self::FALLBACK;
    }
}
