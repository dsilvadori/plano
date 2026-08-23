<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class UserSpreadsheetImporter
{
    public function __construct(
        protected UserSpreadsheetParser $parser,
        protected CourseAccessResolver $courseAccessResolver,
    ) {}

    public function import(string $path, bool $sendFirstAccessEmail = false): array
    {
        $payload = $this->parser->parse($path);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'linked_courses' => 0,
            'missing_courses' => 0,
            'emails_sent' => 0,
            'total_rows' => $payload['total_rows'],
            'active_rows' => $payload['active_rows'],
            'skipped_inactive_rows' => $payload['skipped_inactive_rows'],
            'invalid_rows' => $payload['invalid_rows'],
        ];

        DB::transaction(function () use ($payload, $sendFirstAccessEmail, &$stats): void {
            foreach ($payload['students'] as $studentData) {
                $user = User::query()->firstOrNew(['email' => $studentData['email']]);
                $wasRecentlyCreated = ! $user->exists;

                $user->name = $studentData['name'];

                if ($wasRecentlyCreated) {
                    $user->role = 'student';
                    $user->password = Str::password(24);
                }

                $user->save();
                $stats[$wasRecentlyCreated ? 'created' : 'updated']++;

                $resolvedCourses = $this->resolveCourses($studentData['courses']);

                foreach ($resolvedCourses as $course) {
                    $attached = $user->courses()->syncWithoutDetaching([
                        $course->id => ['source' => 'spreadsheet'],
                    ]);

                    $stats['linked_courses'] += count($attached['attached'] ?? []);
                }

                $stats['missing_courses'] += $this->countMissingCourses($studentData['courses']);

                if ($sendFirstAccessEmail && $wasRecentlyCreated) {
                    $token = Password::broker('first_access')->createToken($user);
                    $user->sendSetPasswordNotification($token);
                    $stats['emails_sent']++;
                }
            }
        });

        return $stats;
    }

    protected function resolveCourses(array $courses): array
    {
        return collect($courses)
            ->flatMap(fn (array $courseData) => $this->courseAccessResolver->coursesForProduct(
                $courseData['tutory_product_id'] ?? null,
                $courseData['name'] ?? null,
            ))
            ->unique('id')
            ->values()
            ->all();
    }

    protected function countMissingCourses(array $courses): int
    {
        return collect($courses)
            ->filter(fn (array $courseData): bool => $this->courseAccessResolver
                ->coursesForProduct($courseData['tutory_product_id'] ?? null, $courseData['name'] ?? null)
                ->isEmpty())
            ->count();
    }
}
