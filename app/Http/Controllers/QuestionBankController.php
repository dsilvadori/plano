<?php

namespace App\Http\Controllers;

use App\Models\CourseModule;
use App\Models\CourseModuleTrack;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\QuestionBank;
use App\Models\StudyPlan;
use App\Support\QuestionTextRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuestionBankController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $banks = QuestionBank::query()
            ->with(['modules', 'tracks', 'lessons'])
            ->withCount(['questions' => fn ($query) => $query->where('status', 'published')])
            ->where('status', 'published')
            ->where(function (Builder $query) use ($user): void {
                if ($user->isAdmin()) {
                    return;
                }

                $this->scopeAccessibleBanks($query, $user);
            })
            ->orderBy('title')
            ->get();

        return view('dashboard.questions.index', [
            'banks' => $banks,
        ]);
    }

    public function show(Request $request, QuestionBank $questionBank): View
    {
        $user = $request->user();

        abort_unless($user->canAccessStudentArea(), 403);
        abort_unless($questionBank->status === 'published', 404);
        abort_unless($this->userCanAccessBank($user, $questionBank), 403);

        $questionBank->load(['modules', 'tracks', 'lessons']);
        $query = $questionBank->questions()
            ->with(['options', 'attempts' => fn ($query) => $query
                ->where('user_id', $user->id)
                ->latest('answered_at')])
            ->where('status', 'published');

        $questions = $query->get();
        $returnPlan = null;

        if ($request->integer('plan_id')) {
            $returnPlan = StudyPlan::query()
                ->whereKey($request->integer('plan_id'))
                ->where('user_id', $user->id)
                ->first();
        }

        return view('dashboard.questions.show', [
            'bank' => $questionBank,
            'questions' => $questions,
            'returnPlan' => $returnPlan,
        ]);
    }

    public function answer(Request $request, Question $question): RedirectResponse|JsonResponse
    {
        $user = $request->user();

        abort_unless($user->canAccessStudentArea(), 403);

        $question->load('bank');

        abort_unless($question->status === 'published', 404);
        abort_unless($question->bank && $this->userCanAccessBank($user, $question->bank), 403);

        $data = $request->validate([
            'question_option_id' => ['required', 'integer', 'exists:question_options,id'],
        ]);

        $option = $question->options()->whereKey($data['question_option_id'])->firstOrFail();

        QuestionAttempt::query()->create([
            'user_id' => $user->id,
            'question_id' => $question->id,
            'question_option_id' => $option->id,
            'answer_label' => $option->label,
            'is_correct' => $option->is_correct,
            'answered_at' => now(),
        ]);

        if ($request->expectsJson()) {
            $question->load('options');

            return response()->json([
                'question_id' => $question->id,
                'selected_option_id' => $option->id,
                'is_correct' => (bool) $option->is_correct,
                'answer_key' => $question->answer_key ? strtoupper($question->answer_key) : null,
                'commentary' => $question->commentary ?: 'Comentário em preparação.',
                'commentary_html' => (string) QuestionTextRenderer::renderCommentary($question->commentary ?: 'Comentário em preparação.'),
                'correct_option_ids' => $question->options
                    ->where('is_correct', true)
                    ->pluck('id')
                    ->values(),
            ]);
        }

        $returnUrl = (string) $request->input('return_url', url()->previous());
        $previousUrl = strtok(str_starts_with($returnUrl, url('/')) ? $returnUrl : url()->previous(), '#');

        return redirect()
            ->to($previousUrl)
            ->with('answered_question_id', $question->id);
    }

    protected function userCanAccessBank($user, QuestionBank $bank): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $bank->loadMissing(['modules', 'tracks']);

        if ($bank->modules->isEmpty() && $bank->tracks->isEmpty() && $bank->lessons->isEmpty()) {
            return true;
        }

        $moduleIds = $this->accessibleModuleIds($user);
        $trackIds = $this->accessibleTrackIds($user);
        $lessonIds = $this->accessibleLessonIds($user);

        return $bank->modules->pluck('id')->intersect($moduleIds)->isNotEmpty()
            || $bank->tracks->pluck('id')->intersect($trackIds)->isNotEmpty()
            || $bank->lessons->pluck('id')->intersect($lessonIds)->isNotEmpty();
    }

    protected function scopeAccessibleBanks(Builder $query, $user): void
    {
        $moduleIds = $this->accessibleModuleIds($user);
        $trackIds = $this->accessibleTrackIds($user);
        $lessonIds = $this->accessibleLessonIds($user);

        $query->where(function (Builder $query) use ($moduleIds, $trackIds, $lessonIds): void {
            $query->whereDoesntHave('modules')
                ->whereDoesntHave('tracks')
                ->whereDoesntHave('lessons')
                ->orWhereHas('modules', fn (Builder $query) => $query->whereIn('course_modules.id', $moduleIds))
                ->orWhereHas('tracks', fn (Builder $query) => $query->whereIn('course_module_tracks.id', $trackIds))
                ->orWhereHas('lessons', fn (Builder $query) => $query->whereIn('lessons.id', $lessonIds));
        });
    }

    protected function accessibleModuleIds($user): array
    {
        $courseIds = $user->availableCoursesQuery()->pluck('courses.id');

        return CourseModule::query()
            ->whereIn('course_id', $courseIds)
            ->orWhereHas('courses', fn (Builder $query) => $query->whereIn('courses.id', $courseIds))
            ->pluck('id')
            ->all();
    }

    protected function accessibleTrackIds($user): array
    {
        $courseIds = $user->availableCoursesQuery()->pluck('courses.id');

        return CourseModuleTrack::query()
            ->whereHas('module', fn (Builder $query) => $query->whereIn('course_id', $courseIds))
            ->orWhereHas('courses', fn (Builder $query) => $query->whereIn('courses.id', $courseIds))
            ->pluck('id')
            ->all();
    }

    protected function accessibleLessonIds($user): array
    {
        $courseIds = $user->availableCoursesQuery()->pluck('courses.id');

        return Lesson::query()
            ->whereIn('course_id', $courseIds)
            ->orWhereHas('modules.courses', fn (Builder $query) => $query->whereIn('courses.id', $courseIds))
            ->orWhereHas('tracks.courses', fn (Builder $query) => $query->whereIn('courses.id', $courseIds))
            ->pluck('id')
            ->all();
    }
}
