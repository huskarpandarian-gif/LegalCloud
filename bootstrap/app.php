<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureActiveOrganization;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireRole;
use App\Domain\AI\EmbeddingProviderFailed;
use App\Domain\AI\ProviderNotConfigured;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization.active' => EnsureActiveOrganization::class,
            'role' => RequireRole::class,
            'permission' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (EmbeddingProviderFailed $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 'embedding_provider_failed',
                'message' => 'The configured embedding provider could not complete the request.',
            ], 503);
        });
        $exceptions->render(function (ProviderNotConfigured $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'code' => 'provider_not_configured',
                'message' => 'The requested AI provider is not configured.',
            ], 503);
        });
    })->create();
