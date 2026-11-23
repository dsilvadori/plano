<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

class StudentCourse extends Pivot
{
    /** @use HasFactory<\Database\Factories\StudentCourseFactory> */
    use HasFactory;

    /** Duração padrão do acesso a um curso comprado ou liberado. */
    public const ACCESS_DURATION_YEARS = 1;

    protected $table = 'student_courses';

    public $incrementing = true;

    protected $fillable = [
        'user_id',
        'course_id',
        'source',
        'external_purchase_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Todo vínculo novo (webhook, admin, planilha, combo) ganha 1 ano de acesso,
        // a menos que quem criou informe explicitamente o prazo (null = sem limite).
        static::creating(function (StudentCourse $studentCourse): void {
            if (! array_key_exists('expires_at', $studentCourse->getAttributes())) {
                $studentCourse->expires_at = self::defaultExpiration();
            }
        });
    }

    public static function defaultExpiration(?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addYears(self::ACCESS_DURATION_YEARS);
    }

    /**
     * Renova o acesso para 1 ano a partir de agora, sem nunca encurtar o prazo atual
     * nem limitar um acesso que o admin deixou sem prazo.
     */
    public function renew(): void
    {
        if ($this->expires_at === null) {
            return;
        }

        $renewed = self::defaultExpiration();

        if ($renewed->greaterThan($this->expires_at)) {
            $this->forceFill(['expires_at' => $renewed])->save();
        }
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('student_courses.expires_at')
            ->orWhere('student_courses.expires_at', '>', now()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
