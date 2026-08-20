<?php

use App\Http\Controllers\Admin\UserImpersonationController;
use App\Http\Controllers\CourseCatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuestionBankController;
use App\Http\Controllers\StudyPlanController;
use App\Http\Controllers\ThumbnailController;
use App\Http\Controllers\TutoryWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect('/login');
    }

    return auth()->user()->isAdmin()
        ? redirect('/admin')
        : redirect()->route('dashboard');
})->name('home');

Route::post('/webhooks/tutory/{secret?}', TutoryWebhookController::class)
    ->where('secret', '[^/]+')
    ->name('webhooks.tutory');

Route::middleware('auth')->group(function () {
    Route::post('/admin/users/{user}/impersonate', [UserImpersonationController::class, 'start'])
        ->name('admin.users.impersonate');
    Route::post('/admin/impersonation/stop', [UserImpersonationController::class, 'stop'])
        ->name('admin.impersonation.stop');

    Route::get('/media/thumbnails/{path}', ThumbnailController::class)
        ->where('path', '.*')
        ->name('media.thumbnails.show');

    Route::get('/dashboard', [CourseCatalogController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/plano-de-estudos', DashboardController::class)->name('study-plans.dashboard');
    Route::get('/dashboard/cursos', [CourseCatalogController::class, 'index'])->name('courses.index');
    Route::get('/dashboard/meus-cursos', [CourseCatalogController::class, 'mine'])->name('courses.mine');
    Route::get('/dashboard/cursos/{course:slug}', [CourseCatalogController::class, 'show'])->name('courses.show');
    Route::get('/dashboard/cursos/{course:slug}/modulos', [CourseCatalogController::class, 'modules'])->name('courses.modules.index');
    Route::get('/dashboard/cursos/{course:slug}/modulos/{module}/trilhas', [CourseCatalogController::class, 'moduleTracks'])->name('courses.modules.tracks.index');
    Route::get('/dashboard/cursos/{course:slug}/modulos/{module}/trilhas/{track}/aulas', [CourseCatalogController::class, 'trackLessons'])->name('courses.modules.tracks.lessons.index');
    Route::get('/dashboard/cursos/{course:slug}/aulas/{lesson}', [CourseCatalogController::class, 'lesson'])->name('courses.lessons.show');
    Route::get('/dashboard/cursos/{course:slug}/aulas/{lesson}/resumo.pdf', [CourseCatalogController::class, 'downloadLessonSummary'])->name('courses.lessons.summary.pdf');
    Route::post('/dashboard/cursos/{course:slug}/aulas/{lesson}/ia/panda', [CourseCatalogController::class, 'syncPandaAi'])->name('courses.lessons.ai.panda');
    Route::post('/dashboard/cursos/{course:slug}/aulas/{lesson}/concluir', [CourseCatalogController::class, 'completeLesson'])->name('courses.lessons.complete');
    Route::post('/dashboard/cursos/{course:slug}/aulas/{lesson}/comentarios', [CourseCatalogController::class, 'storeComment'])->name('courses.lessons.comments.store');
    Route::get('/dashboard/questoes', [QuestionBankController::class, 'index'])->name('questions.index');
    Route::get('/dashboard/questoes/{questionBank}', [QuestionBankController::class, 'show'])->name('questions.show');
    Route::post('/dashboard/questoes/responder/{question}', [QuestionBankController::class, 'answer'])->name('questions.answer');
    Route::get('/dashboard/planos', [StudyPlanController::class, 'index'])->name('study-plans.index');
    Route::get('/dashboard/plano/novo', [StudyPlanController::class, 'create'])->name('study-plans.create');
    Route::post('/dashboard/plano', [StudyPlanController::class, 'store'])->name('study-plans.store');
    Route::get('/dashboard/plano/{studyPlan}/editar', [StudyPlanController::class, 'edit'])
        ->can('update', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.edit');
    Route::match(['put', 'patch'], '/dashboard/plano/{studyPlan}', [StudyPlanController::class, 'update'])
        ->can('update', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.update');
    Route::post('/dashboard/plano/{studyPlan}/reajustar', [StudyPlanController::class, 'rebalance'])
        ->can('update', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.rebalance');
    Route::get('/dashboard/plano/{studyPlan}', [StudyPlanController::class, 'show'])
        ->can('view', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.show');
    Route::post('/dashboard/plano/{studyPlan}/items/{item}/toggle', [StudyPlanController::class, 'toggle'])
        ->can('view', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.items.toggle');
    Route::get('/dashboard/plano/{studyPlan}/items/{item}/aulas/{lesson}', [StudyPlanController::class, 'lesson'])
        ->can('view', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.items.lessons.show');
    Route::delete('/dashboard/plano/{studyPlan}', [StudyPlanController::class, 'destroy'])
        ->can('delete', 'studyPlan')
        ->missing(fn () => auth()->user()?->isAdmin() ? redirect('/admin') : redirect()->route('dashboard'))
        ->name('study-plans.destroy');

    Route::fallback(function () {
        return auth()->user()?->isAdmin()
            ? redirect('/admin')
            : redirect()->route('dashboard');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

require __DIR__.'/auth.php';
