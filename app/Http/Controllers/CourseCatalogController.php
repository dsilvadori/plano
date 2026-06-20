<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\CourseSphere;
use App\Models\EducationLevel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class CourseCatalogController extends Controller
{
    public function index(): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $accessibleCourseIds = $user->availableCoursesQuery()->pluck('courses.id');

        $featuredCourses = $this->publishedCourses()
            ->where('is_featured', true)
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(8)
            ->get();

        $latestCourses = $this->publishedCourses()
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->latest()
            ->limit(8)
            ->get();

        $coursesBySphere = CourseSphere::query()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $query) => $this->publishedCourses($query))
            ->with(['courses' => fn ($query) => $this->publishedCourses($query)
                ->with(['sphere', 'educationLevel'])
                ->withCount(['modules', 'lessons'])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(6),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $educationLevels = EducationLevel::query()
            ->where('is_active', true)
            ->whereHas('courses', fn (Builder $query) => $this->publishedCourses($query))
            ->withCount(['courses' => fn (Builder $query) => $this->publishedCourses($query)])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return view('dashboard.courses.index', [
            'user' => $user,
            'accessibleCourseIds' => $accessibleCourseIds,
            'featuredCourses' => $featuredCourses,
            'latestCourses' => $latestCourses,
            'coursesBySphere' => $coursesBySphere,
            'educationLevels' => $educationLevels,
        ]);
    }

    public function mine(): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $courses = $user->availableCoursesQuery()
            ->where('status', 'published')
            ->with(['sphere', 'educationLevel'])
            ->withCount(['modules', 'lessons'])
            ->orderBy('name')
            ->get();

        return view('dashboard.courses.mine', [
            'courses' => $courses,
        ]);
    }

    public function show(Course $course): View
    {
        $user = request()->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($course->is_active && $course->status === 'published', 404);

        $hasAccess = $user->availableCoursesQuery()
            ->whereKey($course->getKey())
            ->exists();

        $course->load([
            'sphere',
            'educationLevel',
            'modules' => fn ($query) => $query
                ->where('is_active', true)
                ->with(['onlineLessons' => fn ($lessonQuery) => $lessonQuery
                    ->where('status', 'published')
                    ->orderBy('sort_order')
                    ->orderBy('title'),
                ])
                ->orderBy('sort_order')
                ->orderBy('name'),
        ])->loadCount(['modules', 'lessons']);

        return view('dashboard.courses.show', [
            'course' => $course,
            'hasAccess' => $hasAccess,
        ]);
    }

    protected function publishedCourses(?Builder $query = null): Builder
    {
        return ($query ?? Course::query())
            ->where('is_active', true)
            ->where('status', 'published');
    }
}
