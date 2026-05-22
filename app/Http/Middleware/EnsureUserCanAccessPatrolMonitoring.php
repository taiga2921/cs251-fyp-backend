<?php

namespace App\Http\Middleware;

use App\Support\PatrolChannelAuthorizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserCanAccessPatrolMonitoring
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

        if (! PatrolChannelAuthorizer::canAccessPatrolMonitoring($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators and security operators may perform this action.',
                'data' => null,
            ], 403);
        }

        return $next($request);
    }
}
