<?php

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\CaseController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProcessingJobController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'home']);
Route::get('/dashboard', [PageController::class, 'dashboard']);
Route::get('/usuarios', [PageController::class, 'users']);
Route::get('/categorias', [PageController::class, 'categories']);
Route::get('/expedientes', [PageController::class, 'cases']);
Route::get('/expedientes/{expediente}', [PageController::class, 'cases']);
Route::get('/documentos', [PageController::class, 'documents']);
Route::get('/documentos/{documento}', [PageController::class, 'documents']);
Route::get('/busqueda', [PageController::class, 'search']);
Route::get('/asistente', [PageController::class, 'assistant']);
Route::get('/ocr', [PageController::class, 'ocr']);
Route::get('/notificaciones', [PageController::class, 'notifications']);
Route::get('/auditoria', [PageController::class, 'audit']);
Route::get('/settings/profile', [PageController::class, 'profile']);
Route::get('/settings/password', [PageController::class, 'password']);
Route::get('/settings/appearance', [PageController::class, 'appearance']);
Route::any('/settings', [PageController::class, 'settings']);

Route::middleware(['auth:api', 'organization.active'])->group(function (): void {
    Route::post('/usuarios', [UserController::class, 'store'])->middleware('permission:users.manage');
    Route::post('/categorias', [CategoryController::class, 'store'])->middleware('permission:categories.manage');
    Route::post('/expedientes', [CaseController::class, 'store'])->middleware('permission:cases.write');
    Route::post('/documentos', [DocumentController::class, 'store'])->middleware('permission:documents.write');
    Route::post('/documentos/ejemplo', [DocumentController::class, 'storeExample'])->middleware('permission:documents.write');
    Route::get('/documentos/{documentId}/archivo', [DocumentController::class, 'downloadCurrent'])->middleware('permission:documents.read');
    Route::post('/ocr/{documentId}', [ProcessingJobController::class, 'startOcr'])->middleware('permission:documents.process');
    Route::post('/asistente', [AssistantController::class, 'query'])->middleware(['throttle:api', 'permission:assistant.use']);
    Route::post('/notificaciones/{notificationId}/leer', [NotificationController::class, 'markRead']);
    Route::patch('/settings/profile', [ProfileController::class, 'update']);
    Route::delete('/settings/profile', [ProfileController::class, 'destroy']);
    Route::put('/settings/password', [ProfileController::class, 'updatePassword']);
});
