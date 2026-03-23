<?php

namespace Npabisz\LaravelMetrics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('metrics.routes.token');

        if (empty($token)) {
            abort(403, 'Monitoring API token not configured.');
        }

        $bearer = $request->bearerToken();

        if (!$bearer || !hash_equals($token, $bearer)) {
            abort(401, 'Invalid monitoring API token.');
        }

        return $next($request);
    }
}
