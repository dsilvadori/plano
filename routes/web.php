<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StudyPlanController;
use App\Http\Controllers\TutoryWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return auth()->user()->isAdmin()
        ? redirect('/admin')
        : redirect()->route('dashboard');
})->name('home');

Route::post('/webhooks/tutory', TutoryWebhookController::class)->name('webhooks.tutory');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
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
