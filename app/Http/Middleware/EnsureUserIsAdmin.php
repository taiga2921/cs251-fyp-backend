<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        }

        $user->loadMissing('role');

        if (! is_string($user->role?->name) || strcasecmp($user->role->name, 'Admin') !== 0) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators may perform this action.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
