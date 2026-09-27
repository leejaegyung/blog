<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminLlmController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostDraftController;
use App\Http\Controllers\Api\PostImageController;
use App\Http\Controllers\Api\PostPlanController;
use App\Http\Controllers\Api\PostPublishController;
use App\Http\Controllers\Api\PostQualityController;
use App\Http\Controllers\Api\PostRewriteController;
use App\Http\Controllers\Api\ProjectAnalysisController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\WizardController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::prefix('admin')->controller(AdminController::class)->group(function () {
        Route::get('/usage', 'usage');
        Route::get('/failed-jobs', 'failedJobs');
        Route::post('/failed-jobs/{uuid}/retry', 'retry');
        Route::delete('/failed-jobs/{uuid}', 'forget');
    });
    Route::prefix('admin/llm')->controller(AdminLlmController::class)->group(function () {
        Route::get('/', 'show');
        Route::put('/keys/{provider}', 'updateKey');
        Route::delete('/keys/{provider}', 'deleteKey');
        Route::put('/route', 'updateRoute');
        Route::post('/test', 'test');
    });

    Route::apiResource('projects', ProjectController::class);
    Route::get('/projects/{project}/analysis', [ProjectAnalysisController::class, 'show']);
    Route::post('/projects/{project}/analyze', [ProjectAnalysisController::class, 'analyze']);

    Route::controller(ReferenceController::class)->group(function () {
        Route::get('/projects/{project}/references', 'index');
        Route::post('/projects/{project}/references', 'store');
        Route::post('/references/{reference}/text', 'text');
        Route::post('/references/{reference}/parse', 'reparse');
        Route::delete('/references/{reference}', 'destroy');
    });

    Route::post('/posts/start', [WizardController::class, 'start']);
    Route::post('/posts/{post}/autopilot', [WizardController::class, 'autopilot']);
    Route::apiResource('posts', PostController::class)->except('destroy');
    Route::post('/posts/{post}/plan', [PostPlanController::class, 'store']);
    Route::put('/posts/{post}/plan', [PostPlanController::class, 'update']);
    Route::post('/posts/{post}/generate', [PostDraftController::class, 'store']);
    Route::post('/posts/{post}/rewrite', PostRewriteController::class);
    Route::post('/posts/{post}/quality-check', PostQualityController::class);
    Route::controller(PostPublishController::class)->group(function () {
        Route::post('/posts/{post}/export', 'export');
        Route::get('/posts/{post}/export/photos.zip', 'photos');
        Route::post('/posts/{post}/publish', 'publish');
        Route::get('/posts/{post}/publish-status', 'status');
    });

    Route::scopeBindings()->prefix('posts/{post}/images')->controller(PostImageController::class)->group(function () {
        Route::post('/', 'store');
        Route::patch('/order', 'reorder');
        Route::post('/analyze', 'analyze');
        Route::delete('/{image}', 'destroy');
        Route::get('/{image}/file', 'file');
    });
});
