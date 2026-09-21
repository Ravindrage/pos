<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extra safety net on top of the model-level BelongsToTenant global scope:
 * refuses any authenticated request from a user that isn't attached to a
 * business at all (e.g. a half-created account), rather than silently
 * letting an unscoped query run.
 */
class EnsureBusinessScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->business_id) {
            return response()->json([
                'message' => 'No business associated with this account.',
            ], 403);
        }

        return $next($request);
    }
}
