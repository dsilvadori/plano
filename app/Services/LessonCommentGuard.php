<?php

namespace App\Services;

use App\Models\LessonComment;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LessonCommentGuard
{
    protected const BLOCKED_TERMS = [
        'arrombado',
        'buceta',
        'caralho',
        'cu',
        'fdp',
        'foda',
        'merda',
        'porra',
        'puta',
        'puto',
        'vai se foder',
        'vsf',
    ];

    public function validate(User $user, string $body): void
    {
        $body = trim($body);

        if ($this->containsProfanity($body)) {
            throw ValidationException::withMessages([
                'body' => 'Revise sua mensagem antes de enviar. Não permitimos palavrões nos comentários.',
            ]);
        }

        $recentComment = LessonComment::query()
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        if ($recentComment && $recentComment->created_at?->gt(now()->subSeconds(45))) {
            throw ValidationException::withMessages([
                'body' => 'Aguarde alguns segundos antes de enviar outro comentário.',
            ]);
        }

        $hourlyCount = LessonComment::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($hourlyCount >= 8) {
            throw ValidationException::withMessages([
                'body' => 'Você atingiu o limite de comentários por hora. Tente novamente mais tarde.',
            ]);
        }

        $normalizedBody = $this->normalize($body);
        $duplicateExists = LessonComment::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->get(['body'])
            ->contains(fn (LessonComment $comment): bool => $this->normalize($comment->body) === $normalizedBody);

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'body' => 'Você já enviou uma mensagem igual recentemente.',
            ]);
        }
    }

    protected function containsProfanity(string $body): bool
    {
        $normalized = $this->normalize($body);

        foreach (self::BLOCKED_TERMS as $term) {
            if (str_contains($normalized, $this->normalize($term))) {
                return true;
            }
        }

        return false;
    }

    protected function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
