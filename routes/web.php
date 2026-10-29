<?php

use App\Http\Controllers\ActiveWorkoutController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BodyWeightController;
use App\Http\Controllers\CoachAssignmentController;
use App\Http\Controllers\CoachConnectionController;
use App\Http\Controllers\CoachController;
use App\Http\Controllers\CoachScheduleController;
use App\Http\Controllers\CoachSubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExerciseProgressController;
use App\Http\Controllers\PlanScheduleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ScheduleChangeController;
use App\Http\Controllers\ScheduleEntryController;
use App\Http\Controllers\SessionExerciseController;
use App\Http\Controllers\SessionSetController;
use App\Http\Controllers\TrainingDayController;
use App\Http\Controllers\TrainingHistoryController;
use App\Http\Controllers\TrainingPlanController;
use App\Http\Middleware\CoachWorkspace;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/dashboard' : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:20,1');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/onboarding', [ProfileController::class, 'onboarding'])->name('onboarding');
    Route::post('/onboarding', [ProfileController::class, 'complete'])->name('onboarding.complete');
    Route::post('/onboarding/skip', [ProfileController::class, 'skip'])->name('onboarding.skip');
    Route::get('/progress', ProgressController::class)->name('progress');
    Route::get('/progress/exercises', ExerciseProgressController::class)->name('progress.exercises');
    Route::patch('/training-exercises/{trainingExercise}/progression', [ExerciseProgressController::class, 'apply'])->name('training-exercises.progression');
    Route::post('/body-weight', [BodyWeightController::class, 'store'])->name('body-weight.store');
    Route::put('/body-weight/{bodyWeightEntry}', [BodyWeightController::class, 'update'])->name('body-weight.update');
    Route::delete('/body-weight/{bodyWeightEntry}', [BodyWeightController::class, 'destroy'])->name('body-weight.destroy');
    Route::post('/body-weight-check-in/dismiss', [BodyWeightController::class, 'dismiss'])->name('body-weight.dismiss');
    Route::get('/recommendations', [RecommendationController::class, 'index'])->name('recommendations.index');
    Route::post('/recommendations', [RecommendationController::class, 'store'])->name('recommendations.store');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::redirect('/overview', '/dashboard');
    Route::get('/training-history', [TrainingHistoryController::class, 'index'])->name('training-history.index');
    Route::get('/training-history/{scheduleEntry}', [TrainingHistoryController::class, 'show'])->name('training-history.show');
    Route::resource('training-plans', TrainingPlanController::class)->parameters(['training-plans' => 'trainingPlan']);
    Route::post('/training-plans/{trainingPlan}/duplicate', [TrainingPlanController::class, 'duplicate'])->name('training-plans.duplicate');
    Route::get('/training-plans/{trainingPlan}/schedule-preview', [PlanScheduleController::class, 'preview'])->name('training-plans.schedule-preview');
    Route::post('/training-plans/{trainingPlan}/schedule', [PlanScheduleController::class, 'store'])->name('training-plans.schedule');
    Route::get('/training-plans/{trainingPlan}/days/create', [TrainingDayController::class, 'create'])->name('training-days.create');
    Route::post('/training-plans/{trainingPlan}/days', [TrainingDayController::class, 'store'])->name('training-days.store');
    Route::patch('/training-plans/{trainingPlan}/days/reorder', [TrainingDayController::class, 'reorderDays'])->name('training-days.reorder');
    Route::resource('training-days', TrainingDayController::class)->only(['show', 'edit', 'update', 'destroy'])->parameters(['training-days' => 'trainingDay']);
    Route::post('/training-days/{trainingDay}/duplicate', [TrainingDayController::class, 'duplicate'])->name('training-days.duplicate');
    Route::patch('/training-days/{trainingDay}/exercises/reorder', [TrainingDayController::class, 'reorderExercises'])->name('exercises.reorder');
    Route::post('/schedule-entries/{scheduleEntry}/workout/start', [ActiveWorkoutController::class, 'start'])->name('workout.start');
    Route::get('/schedule-entries/{scheduleEntry}/workout', [ActiveWorkoutController::class, 'show'])->name('workout.show');
    Route::post('/schedule-entries/{scheduleEntry}/workout/finish', [ActiveWorkoutController::class, 'finish'])->name('workout.finish');
    Route::post('/schedule-entries/{scheduleEntry}/workout/discard', [ActiveWorkoutController::class, 'discard'])->name('workout.discard');
    Route::patch('/session-exercises/{sessionExercise}/status', [SessionExerciseController::class, 'status'])->name('session-exercises.status');
    Route::post('/session-exercises/{sessionExercise}/sets', [SessionExerciseController::class, 'storeSet'])->name('session-exercises.sets.store');
    Route::patch('/session-sets/{sessionSet}', [SessionSetController::class, 'update'])->name('session-sets.update');
    Route::delete('/session-sets/{sessionSet}', [SessionSetController::class, 'destroy'])->name('session-sets.destroy');
    Route::get('/calendar', [ScheduleEntryController::class, 'calendar'])->name('calendar');
    Route::resource('schedule-entries', ScheduleEntryController::class)->except('index')->parameters(['schedule-entries' => 'scheduleEntry']);
    Route::patch('/schedule-entries/{scheduleEntry}/status', [ScheduleEntryController::class, 'status'])->name('schedule-entries.status');
    Route::patch('/schedule-entries/{scheduleEntry}/weights', [ScheduleEntryController::class, 'weights'])->name('schedule-entries.weights');
});

// Coaching capabilities are additional access, never a substituted authentication identity.
Route::middleware('auth')->group(function () {
    Route::get('/profile/coach', [CoachController::class, 'setup']);
    Route::post('/profile/coach', [CoachController::class, 'updateProfile']);
    Route::post('/workspace', [CoachController::class, 'switch']);
    Route::get('/my-coaching', [CoachConnectionController::class, 'index']);
    Route::post('/my-coaching/qr', [CoachConnectionController::class, 'qr'])->middleware('throttle:coach-qr');
    Route::post('/my-coaching/relationships/{relationship}', [CoachConnectionController::class, 'respond']);
    Route::prefix('coach')->middleware(CoachWorkspace::class)->group(function () {
        Route::get('/', [CoachController::class, 'overview']);
        Route::get('/trainees', [CoachController::class, 'trainees']);
        Route::post('/lookup', [CoachConnectionController::class, 'lookup'])->middleware('throttle:coach-lookup');
        Route::get('/connect/{token}', [CoachConnectionController::class, 'connect'])->middleware('throttle:coach-token');
        Route::post('/requests', [CoachConnectionController::class, 'store'])->middleware('throttle:coach-requests');
        Route::get('/subscriptions', [CoachSubscriptionController::class, 'index']);
        Route::post('/subscription-plans', [CoachSubscriptionController::class, 'package']);
        Route::put('/subscription-plans/{package}', [CoachSubscriptionController::class, 'package']);
        Route::post('/subscriptions', [CoachSubscriptionController::class, 'store']);
        Route::patch('/subscriptions/{subscription}', [CoachSubscriptionController::class, 'update']);
        Route::get('/trainees/{trainee}', [CoachController::class, 'trainee']);
    });
});

Route::middleware(['auth', CoachWorkspace::class])->prefix('coach')->group(function () {
    foreach (['' => 'coach.templates.', '/trainees/{trainee}' => 'coach.trainee.'] as $prefix => $name) {
        Route::prefix($prefix)->name($name)->group(function () {
            Route::resource('training-plans', TrainingPlanController::class)->parameters(['training-plans' => 'trainingPlan']);
            Route::post('/training-plans/{trainingPlan}/duplicate', [TrainingPlanController::class, 'duplicate']);
            Route::get('/training-plans/{trainingPlan}/days/create', [TrainingDayController::class, 'create']);
            Route::post('/training-plans/{trainingPlan}/days', [TrainingDayController::class, 'store']);
            Route::patch('/training-plans/{trainingPlan}/days/reorder', [TrainingDayController::class, 'reorderDays']);
            Route::resource('training-days', TrainingDayController::class)->only(['show', 'edit', 'update', 'destroy'])->parameters(['training-days' => 'trainingDay']);
            Route::post('/training-days/{trainingDay}/duplicate', [TrainingDayController::class, 'duplicate']);
            Route::patch('/training-days/{trainingDay}/exercises/reorder', [TrainingDayController::class, 'reorderExercises']);
        });
    }
    Route::prefix('/trainees/{trainee}')->group(function () {
        Route::get('/assign', [CoachAssignmentController::class, 'create']);
        Route::post('/assign', [CoachAssignmentController::class, 'store']);
        Route::post('/assignments/{assignment}/end', [CoachAssignmentController::class, 'end']);
    });
});

Route::middleware(['auth', CoachWorkspace::class])->prefix('/coach/trainees/{trainee}')->group(function () {
    Route::get('/training-plans/{trainingPlan}/schedule-preview', [PlanScheduleController::class, 'preview']);
    Route::post('/training-plans/{trainingPlan}/schedule', [PlanScheduleController::class, 'store']);
    Route::get('/calendar', [ScheduleEntryController::class, 'calendar']);
    Route::get('/schedule-entries/create', [CoachScheduleController::class, 'create']);
    Route::get('/schedule-entries/{scheduleEntry}', [ScheduleEntryController::class, 'show']);
    Route::get('/schedule-entries/{scheduleEntry}/edit', [CoachScheduleController::class, 'edit']);
    Route::post('/schedule-entries', [CoachScheduleController::class, 'store']);
    Route::put('/schedule-entries/{scheduleEntry}', [CoachScheduleController::class, 'update']);
    Route::delete('/schedule-entries/{scheduleEntry}', [CoachScheduleController::class, 'destroy']);
});

Route::middleware(['auth', CoachWorkspace::class])->prefix('/coach/trainees/{trainee}')->group(function () {
    Route::get('/training-history', [TrainingHistoryController::class, 'index']);
    Route::get('/training-history/{scheduleEntry}', [TrainingHistoryController::class, 'show']);
    Route::get('/progress', ProgressController::class);
    Route::get('/progress/exercises', ExerciseProgressController::class);
    Route::patch('/training-exercises/{trainingExercise}/progression', [ExerciseProgressController::class, 'coachApply']);
});

// Both workspaces use the same signed, locked rescheduling flow.
foreach (['' => ['auth'], '/coach/trainees/{trainee}' => ['auth', CoachWorkspace::class]] as $prefix => $middleware) {
    Route::middleware($middleware)->prefix($prefix)->group(function () {
        Route::post('/schedule-entries/{scheduleEntry}/reschedule-preview', [ScheduleChangeController::class, 'preview']);
        Route::post('/schedule-entries/{scheduleEntry}/reschedule', [ScheduleChangeController::class, 'store']);
        Route::post('/training-plans/{trainingPlan}/stop-schedule-preview', [ScheduleChangeController::class, 'stopPreview']);
        Route::post('/training-plans/{trainingPlan}/stop-schedule', [ScheduleChangeController::class, 'stopStore']);
    });
}
