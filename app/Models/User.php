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

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'tutory_customer_id', 'last_login_at'])]
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

    public function availableCoursesQuery(): Builder
    {
        if ($this->isAdmin() || $this->isSubscriber()) {
            return Course::query()
                ->where('is_active', true);
        }

        $linkedCourses = $this->courses()
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
                $query->whereHas('students', fn (Builder $query) => $query->whereKey($this->getKey()));

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
            ->withPivot(['source', 'external_purchase_id'])
            ->withTimestamps();
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
