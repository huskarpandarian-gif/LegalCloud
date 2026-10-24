<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = $request->user()?->organization;

        // Resolve tenant identity from the authenticated user, never from client-supplied IDs.
        if (! $organization || $organization->status !== 'active') {
            return response()->json([
                'code' => 'organization_unavailable',
                'message' => 'The authenticated account has no active organization.',
            ], 403);
        }

        return $next($request);
    }
}
