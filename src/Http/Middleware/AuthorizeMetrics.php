<?php

namespace Npabisz\LaravelMetrics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeMetrics
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Gate::check('viewMetrics')) {
            abort(403);
        }

        return $next($request);
    }
}
