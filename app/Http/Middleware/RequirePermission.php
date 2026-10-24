<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $allowed = $user && collect($permissions)->contains(fn (string $permission): bool => $user->allows($permission));

        if (! $allowed) {
            return response()->json([
                'code' => 'forbidden',
                'message' => 'You are not allowed to perform this operation.',
            ], 403);
        }

        return $next($request);
    }
}
