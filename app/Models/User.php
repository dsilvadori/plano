<?php

namespace App\Models;

use App\Notifications\CourseAccessGrantedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\SetPasswordNotification;
use Illuminate\Database\Eloquent\Builder;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'role', 'access_expires_at', 'phone', 'tutory_customer_id', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'access_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function normalizeEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }

    public static function findByEmail(?string $email): ?self
    {
        $email = self::normalizeEmail($email);

        if ($email === '') {
            return null;
        }

        return self::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();
    }

    public static function firstOrNewByEmail(?string $email): self
    {
        return self::findByEmail($email) ?? new self(['email' => self::normalizeEmail($email)]);
    }

    public function setEmailAttribute(?string $email): void
    {
        $this->attributes['email'] = self::normalizeEmail($email);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    public function isSubscriber(): bool
    {
        return $this->role === 'subscriber';
    }

    public function canAccessStudentArea(): bool
    {
        return $this->isAdmin() || $this->isStudent() || $this->isSubscriber();
    }

    public function isTestStudent(): bool
    {
        return $this->email === 'aluno@teste.com';
    }

    /**
     * Assinante com prazo em dia (ou sem prazo definido pelo admin).
     */
    public function hasActiveSubscription(): bool
    {
        return $this->isSubscriber()
            && ($this->access_expires_at === null || $this->access_expires_at->isFuture());
    }

    public function subscriptionExpired(): bool
    {
        return $this->isSubscriber() && ! $this->hasActiveSubscription();
    }

    /**
     * Renova a assinatura para 1 ano a partir de agora, sem encurtar o prazo atual.
     * Quem acabou de virar assinante sempre recebe o prazo; um assinante que o admin
     * deixou sem prazo continua sem prazo.
     */
    public function renewSubscriberAccess(bool $wasSubscriber): void
    {
        if ($wasSubscriber && $this->access_expires_at === null) {
            return;
        }

        $renewed = StudentCourse::defaultExpiration();

        if ($this->access_expires_at === null || $renewed->greaterThan($this->access_expires_at)) {
            $this->forceFill(['access_expires_at' => $renewed])->save();
        }
    }

    /**
     * Cursos do aluno cujo prazo de acesso já venceu.
     *
     * @return Collection<int, Course>
     */
    public function expiredCourses(): Collection
    {
        return $this->courses()
            ->whereNotNull('student_courses.expires_at')
            ->where('student_courses.expires_at', '<=', now())
            ->orderBy('courses.name')
            ->get(['courses.id', 'courses.name', 'courses.slug']);
    }

    public function availableCoursesQuery(): Builder
    {
        if ($this->isAdmin() || $this->hasActiveSubscription()) {
            return Course::query()
                ->where('is_active', true);
        }

        $linkedCourses = $this->activeCourses()
            ->select(['courses.id', 'courses.name', 'courses.slug', 'courses.tutory_product_id', 'courses.combo_name'])
            ->get();

        $linkedCourseIds = $linkedCourses->pluck('id')->all();
        $linkedProductIds = $linkedCourses->pluck('tutory_product_id')->filter()->unique()->values()->all();
        $linkedNames = $linkedCourses->pluck('name')->filter()->unique()->values()->all();
        $linkedSlugs = $linkedCourses->pluck('slug')->filter()->unique()->values()->all();
        $linkedComboNames = $linkedCourses->pluck('combo_name')->filter()->unique()->values()->all();

        return Course::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($linkedCourseIds, $linkedProductIds, $linkedNames, $linkedSlugs, $linkedComboNames): void {
                $query->whereHas('studentCourses', fn (Builder $query) => $query
                    ->where('student_courses.user_id', $this->getKey())
                    ->active());

                if ($linkedCourseIds !== []) {
                    $query->orWhereIn('id', $linkedCourseIds);
                }

                if ($linkedProductIds !== []) {
                    $query->orWhereIn('tutory_product_id', $linkedProductIds);
                }

                if ($linkedNames !== []) {
                    $query->orWhereIn('name', $linkedNames);
                }

                if ($linkedSlugs !== []) {
                    $query->orWhereIn('slug', $linkedSlugs);
                }

                if ($linkedComboNames !== []) {
                    $query->orWhereIn('combo_name', $linkedComboNames);
                }
            });
    }

    public function studentCourses(): HasMany
    {
        return $this->hasMany(StudentCourse::class);
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'student_courses')
            ->using(StudentCourse::class)
            ->withPivot(['id', 'source', 'external_purchase_id', 'expires_at'])
            ->withTimestamps();
    }

    /**
     * Cursos vinculados com prazo de acesso em dia.
     */
    public function activeCourses(): BelongsToMany
    {
        return $this->courses()->where(fn (Builder $query) => $query
            ->whereNull('student_courses.expires_at')
            ->orWhere('student_courses.expires_at', '>', now()));
    }

    public function studyPlans(): HasMany
    {
        return $this->hasMany(StudyPlan::class);
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function lessonComments(): HasMany
    {
        return $this->hasMany(LessonComment::class);
    }

    public function sendSetPasswordNotification(string $token): void
    {
        $this->notify(new SetPasswordNotification($token));
    }

    /**
     * @param  Collection<int, Course>  $courses
     */
    public function sendCourseAccessGrantedNotification(Collection $courses): void
    {
        $this->notify(new CourseAccessGrantedNotification($courses));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
