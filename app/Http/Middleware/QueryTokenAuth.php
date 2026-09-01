<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QueryTokenAuth
{
    /**
     * Handle an incoming request.
     * If a 'token' query parameter is present and no Authorization header exists,
     * set the Authorization header to 'Bearer {token}'.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->filled('token') && !$request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
        }

        return $next($request);
    }
}
