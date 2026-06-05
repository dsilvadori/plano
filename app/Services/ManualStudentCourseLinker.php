<?php

namespace App\Services;

use App\Models\User;

class ManualStudentCourseLinker
{
    /**
     * @param  array<int>  $courseIds
     */
    public function link(User $student, array $courseIds): void
    {
        if (! $student->isStudent()) {
            return;
        }

        $payload = collect($courseIds)
            ->filter(fn (mixed $courseId) => is_numeric($courseId))
            ->mapWithKeys(fn (mixed $courseId) => [
                (int) $courseId => [
                    'source' => 'manual',
                    'external_purchase_id' => null,
                ],
            ])
            ->all();

        if ($payload === []) {
            return;
        }

        $student->courses()->syncWithoutDetaching($payload);
    }
}
