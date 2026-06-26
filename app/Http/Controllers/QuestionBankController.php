<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\QuestionAttempt;
use App\Models\QuestionBank;
use App\Models\StudyPlan;
use App\Support\QuestionTextRenderer;
use Illuminate\Contracts\View\View;
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
            ->with('course')
            ->withCount(['questions' => fn ($query) => $query->where('status', 'published')])
            ->where('status', 'published')
            ->where(function ($query) use ($user): void {
                if ($user->isAdmin()) {
                    return;
                }

                $query->whereNull('course_id')
                    ->orWhereIn('course_id', $user->availableCoursesQuery()->pluck('courses.id'));
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

        $questionBank->load('course');
        $query = $questionBank->questions()
            ->with(['options', 'attempts' => fn ($query) => $query
                ->where('user_id', $user->id)
                ->latest('answered_at')])
            ->where('status', 'published');

        if ($request->integer('lesson_id')) {
            $query->where('lesson_id', $request->integer('lesson_id'));
        } elseif ($request->integer('module_id')) {
            $query->where('course_module_id', $request->integer('module_id'));
        }

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
                'commentary_html' => (string) QuestionTextRenderer::render($question->commentary ?: 'Comentário em preparação.'),
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
        if ($user->isAdmin() || ! $bank->course_id) {
            return true;
        }

        return $user->availableCoursesQuery()
            ->where('courses.id', $bank->course_id)
            ->exists();
    }
}
