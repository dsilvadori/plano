<?php

namespace App\Policies;

use App\Models\StudyPlan;
use App\Models\User;

class StudyPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isStudent();
    }

    public function view(User $user, StudyPlan $studyPlan): bool
    {
        return $user->isAdmin() || $studyPlan->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->canAccessStudentArea();
    }

    public function update(User $user, StudyPlan $studyPlan): bool
    {
        return $user->isAdmin() || $studyPlan->user_id === $user->id;
    }

    public function delete(User $user, StudyPlan $studyPlan): bool
    {
        return $user->isAdmin() || $studyPlan->user_id === $user->id;
    }
}
