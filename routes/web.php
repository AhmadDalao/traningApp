<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ScheduleEntryController;
use App\Http\Controllers\TrainingDayController;
use App\Http\Controllers\TrainingPlanController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/dashboard' : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:20,1');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::resource('training-plans', TrainingPlanController::class)->parameters(['training-plans' => 'trainingPlan']);
    Route::get('/training-plans/{trainingPlan}/days/create', [TrainingDayController::class, 'create'])->name('training-days.create');
    Route::post('/training-plans/{trainingPlan}/days', [TrainingDayController::class, 'store'])->name('training-days.store');
    Route::patch('/training-plans/{trainingPlan}/days/reorder', [TrainingDayController::class, 'reorderDays'])->name('training-days.reorder');
    Route::resource('training-days', TrainingDayController::class)->only(['show', 'edit', 'update', 'destroy'])->parameters(['training-days' => 'trainingDay']);
    Route::patch('/training-days/{trainingDay}/exercises/reorder', [TrainingDayController::class, 'reorderExercises'])->name('exercises.reorder');
    Route::get('/calendar', [ScheduleEntryController::class, 'calendar'])->name('calendar');
    Route::resource('schedule-entries', ScheduleEntryController::class)->except('index')->parameters(['schedule-entries' => 'scheduleEntry']);
    Route::patch('/schedule-entries/{scheduleEntry}/status', [ScheduleEntryController::class, 'status'])->name('schedule-entries.status');
});
