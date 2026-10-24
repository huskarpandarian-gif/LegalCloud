<?php

use App\Http\Controllers\Api\V1\AssistantController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CaseController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProcessingJobController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/openapi.yaml', function () {
    return response(file_get_contents(base_path('docs/openapi.yaml')), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('api.v1.openapi');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('/auth/iniciar-sesion', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.iniciar-sesion');
    Route::post('/auth/registrar', [AuthController::class, 'register'])->middleware('throttle:login')->name('auth.registrar');

    Route::middleware(['auth:api', 'organization.active'])->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/cerrar-sesion', [AuthController::class, 'logout'])->name('auth.cerrar-sesion');

        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage')->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage')->name('users.store');
        Route::get('/users/{userId}', [UserController::class, 'show'])->middleware('permission:users.manage')->name('users.show');
        Route::patch('/users/{userId}', [UserController::class, 'update'])->middleware('permission:users.manage')->name('users.update');
        Route::delete('/users/{userId}', [UserController::class, 'destroy'])->middleware('permission:users.manage')->name('users.destroy');

        $users = 'permission:users.manage';
        Route::get('/usuarios', [UserController::class, 'index'])->middleware($users)->name('usuarios.index');
        Route::post('/usuarios', [UserController::class, 'store'])->middleware($users)->name('usuarios.store');
        Route::get('/usuarios/{userId}', [UserController::class, 'show'])->middleware($users)->name('usuarios.show');
        Route::patch('/usuarios/{userId}', [UserController::class, 'update'])->middleware($users)->name('usuarios.update');
        Route::post('/usuarios/{userId}/suspender', [UserController::class, 'suspend'])->middleware($users)->name('usuarios.suspend');
        Route::post('/usuarios/{userId}/reactivar', [UserController::class, 'reactivate'])->middleware($users)->name('usuarios.reactivate');
        Route::put('/usuarios/{userId}/roles', [UserController::class, 'syncRoles'])->middleware($users)->name('usuarios.roles');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::get('/permisos', [RoleController::class, 'permissions'])->name('permisos.index');
        Route::get('/roles/{roleId}', [RoleController::class, 'show'])->name('roles.show');
        Route::patch('/roles/{roleId}', [RoleController::class, 'update'])->middleware($users)->name('roles.update');
        Route::put('/roles/{roleId}/permisos', [RoleController::class, 'syncPermissions'])->middleware($users)->name('roles.permisos');

        Route::get('/cases', [CaseController::class, 'index'])->middleware('permission:cases.read')->name('cases.index');
        Route::post('/cases', [CaseController::class, 'store'])->middleware('permission:cases.write')->name('cases.store');
        Route::get('/cases/{caseId}', [CaseController::class, 'show'])->middleware('permission:cases.read')->name('cases.show');
        Route::patch('/cases/{caseId}', [CaseController::class, 'update'])->middleware('permission:cases.write')->name('cases.update');

        Route::get('/expedientes', [CaseController::class, 'index'])->middleware('permission:cases.read')->name('expedientes.index');
        Route::post('/expedientes', [CaseController::class, 'store'])->middleware('permission:cases.write')->name('expedientes.store');
        Route::get('/expedientes/{caseId}', [CaseController::class, 'show'])->middleware('permission:cases.read')->name('expedientes.show');
        Route::patch('/expedientes/{caseId}', [CaseController::class, 'update'])->middleware('permission:cases.write')->name('expedientes.update');
        Route::get('/expedientes/{caseId}/documentos', [CaseController::class, 'documents'])->middleware('permission:documents.read')->name('expedientes.documentos');
        Route::post('/expedientes/{caseId}/documentos', [DocumentController::class, 'beginUpload'])->middleware('permission:documents.write')->name('expedientes.documentos.iniciar');
        Route::post('/expedientes/{caseId}/documentos/archivo', [DocumentController::class, 'storeInCase'])->middleware('permission:documents.write')->name('expedientes.documentos.archivo');
        Route::get('/expedientes/{caseId}/participantes', [CaseController::class, 'participants'])->middleware('permission:cases.read')->name('expedientes.participantes.index');
        Route::post('/expedientes/{caseId}/participantes', [CaseController::class, 'storeParticipant'])->middleware('permission:cases.write')->name('expedientes.participantes.store');
        Route::delete('/expedientes/{caseId}/participantes/{userId}', [CaseController::class, 'destroyParticipant'])->middleware('permission:cases.write')->name('expedientes.participantes.destroy');
        Route::post('/expedientes/{caseId}/transiciones', [CaseController::class, 'transition'])->middleware('permission:cases.write')->name('expedientes.transiciones');
        Route::get('/expedientes/{caseId}/trazabilidad', [CaseController::class, 'trace'])->middleware('permission:audit.read')->name('expedientes.trazabilidad');

        Route::get('/categories', [CategoryController::class, 'index'])->middleware('permission:categories.read')->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:categories.manage')->name('categories.store');
        Route::get('/categorias', [CategoryController::class, 'index'])->middleware('permission:categories.read')->name('categorias.index');
        Route::post('/categorias', [CategoryController::class, 'store'])->middleware('permission:categories.manage')->name('categorias.store');
        Route::get('/categorias/{categoryId}', [CategoryController::class, 'show'])->middleware('permission:categories.read')->name('categorias.show');
        Route::patch('/categorias/{categoryId}', [CategoryController::class, 'update'])->middleware('permission:categories.manage')->name('categorias.update');
        Route::post('/categorias/{categoryId}/desactivar', [CategoryController::class, 'deactivate'])->middleware('permission:categories.manage')->name('categorias.desactivar');

        $writers = 'permission:documents.write';
        $processors = 'permission:documents.process';
        Route::get('/documents', [DocumentController::class, 'index'])->middleware('permission:documents.read')->name('documents.index');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware($writers)->name('documents.store');
        Route::get('/documents/{documentId}', [DocumentController::class, 'show'])->middleware('permission:documents.read')->name('documents.show');
        Route::patch('/documents/{documentId}', [DocumentController::class, 'update'])->middleware($writers)->name('documents.update');
        Route::delete('/documents/{documentId}', [DocumentController::class, 'destroy'])->middleware($writers)->name('documents.destroy');
        Route::get('/documents/{documentId}/versions', [DocumentController::class, 'versions'])->middleware('permission:documents.read')->name('documents.versions.index');
        Route::post('/documents/{documentId}/versions', [DocumentController::class, 'storeVersion'])->middleware($writers)->name('documents.versions.store');
        Route::get('/documents/{documentId}/versions/{versionId}/download', [DocumentController::class, 'download'])->middleware('permission:documents.read')->name('documents.versions.download');
        Route::post('/documents/{documentId}/ocr', [ProcessingJobController::class, 'startOcr'])->middleware($processors)->name('documents.ocr');
        Route::get('/processing-jobs/{jobId}', [ProcessingJobController::class, 'show'])->middleware($processors)->name('processing-jobs.show');

        Route::post('/documentos/archivo', [DocumentController::class, 'store'])->middleware($writers)->name('documentos.archivo');
        Route::put('/cargas/{token}', [DocumentController::class, 'receiveUpload'])->middleware($writers)->name('cargas.recibir');
        Route::get('/documentos', [DocumentController::class, 'index'])->middleware('permission:documents.read')->name('documentos.index');
        Route::get('/documentos/{documentId}', [DocumentController::class, 'show'])->middleware('permission:documents.read')->name('documentos.show');
        Route::patch('/documentos/{documentId}', [DocumentController::class, 'update'])->middleware($writers)->name('documentos.update');
        Route::post('/documentos/{documentId}/archivar', [DocumentController::class, 'archive'])->middleware($writers)->name('documentos.archivar');
        Route::get('/documentos/{documentId}/versiones', [DocumentController::class, 'versions'])->middleware('permission:documents.read')->name('documentos.versiones.index');
        Route::post('/documentos/{documentId}/versiones', [DocumentController::class, 'storeVersion'])->middleware($writers)->name('documentos.versiones.store');
        Route::get('/documentos/{documentId}/versiones/{versionId}', [DocumentController::class, 'showVersion'])->middleware('permission:documents.read')->name('documentos.versiones.show');
        Route::post('/documentos/{documentId}/versiones/{versionId}/confirmar', [DocumentController::class, 'confirmVersion'])->middleware($writers)->name('documentos.versiones.confirmar');
        Route::get('/documentos/{documentId}/versiones/{versionId}/descarga', [DocumentController::class, 'download'])->middleware('permission:documents.read')->name('documentos.versiones.descarga');
        Route::post('/documentos/{documentId}/versiones/{versionId}/restaurar', [DocumentController::class, 'restoreVersion'])->middleware($writers)->name('documentos.versiones.restaurar');
        Route::get('/documentos/{documentId}/versiones/{versionId}/texto', [DocumentController::class, 'versionText'])->middleware('permission:documents.read')->name('documentos.versiones.texto');
        Route::post('/documentos/{documentId}/versiones/{versionId}/procesamiento', [ProcessingJobController::class, 'reprocess'])->middleware($processors)->name('documentos.procesamiento');
        Route::get('/trabajos/{jobId}', [ProcessingJobController::class, 'show'])->middleware($processors)->name('trabajos.show');
        Route::post('/ingestions', [ProcessingJobController::class, 'ingest'])->middleware($processors)->name('ingestions.store');

        Route::post('/search', [DocumentController::class, 'search'])->middleware(['throttle:api', 'permission:search.use'])->name('search');
        Route::get('/busquedas', [DocumentController::class, 'savedSearches'])->middleware('permission:search.use')->name('busquedas.index');
        Route::post('/busquedas', [DocumentController::class, 'storeSavedSearch'])->middleware(['throttle:api', 'permission:search.use'])->name('busquedas.store');
        Route::get('/busquedas/{searchId}', [DocumentController::class, 'showSavedSearch'])->middleware('permission:search.use')->name('busquedas.show');

        $assistant = 'permission:assistant.use';
        Route::get('/assistant/conversations', [AssistantController::class, 'index'])->middleware($assistant)->name('assistant.conversations.index');
        Route::post('/assistant/conversations', [AssistantController::class, 'store'])->middleware($assistant)->name('assistant.conversations.store');
        Route::post('/assistant/conversations/{conversationId}/messages', [AssistantController::class, 'ask'])->middleware(['throttle:api', $assistant])->name('assistant.messages.store');
        Route::get('/assistant/messages/{messageId}', [AssistantController::class, 'showMessage'])->middleware($assistant)->name('assistant.messages.show');

        Route::get('/conversaciones', [AssistantController::class, 'index'])->middleware($assistant)->name('conversaciones.index');
        Route::post('/conversaciones', [AssistantController::class, 'store'])->middleware($assistant)->name('conversaciones.store');
        Route::get('/conversaciones/{conversationId}', [AssistantController::class, 'show'])->middleware($assistant)->name('conversaciones.show');
        Route::post('/conversaciones/{conversationId}/mensajes', [AssistantController::class, 'ask'])->middleware(['throttle:api', $assistant])->name('conversaciones.mensajes');
        Route::post('/queries', [AssistantController::class, 'query'])->middleware(['throttle:api', $assistant])->name('queries.store');
        Route::post('/resumenes', [AssistantController::class, 'summarize'])->middleware($assistant)->name('resumenes.store');
        Route::get('/resumenes/{summaryId}', [AssistantController::class, 'showSummary'])->middleware($assistant)->name('resumenes.show');

        Route::get('/notifications', [NotificationController::class, 'index'])->middleware('permission:notifications.read')->name('notifications.index');
        Route::patch('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
        Route::get('/notificaciones', [NotificationController::class, 'index'])->middleware('permission:notifications.read')->name('notificaciones.index');
        Route::get('/notificaciones/no-leidas/conteo', [NotificationController::class, 'unreadCount'])->name('notificaciones.conteo');
        Route::post('/notificaciones/lectura', [NotificationController::class, 'markAllRead'])->name('notificaciones.lectura');
        Route::post('/notificaciones/{notificationId}/lectura', [NotificationController::class, 'markRead'])->name('notificaciones.leer');

        Route::get('/audit-events', [AuditController::class, 'index'])->middleware('permission:audit.read')->name('audit.index');
        Route::get('/dashboard', [DashboardController::class, 'show'])->name('dashboard');
    });
});
