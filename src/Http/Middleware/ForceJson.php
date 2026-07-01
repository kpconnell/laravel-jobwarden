<?php

declare(strict_types=1);

namespace JobWarden\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator API is JSON, always. Force the request to "expect JSON" so
 * validation failures, 403s, and 404s render as JSON (422 with errors) instead
 * of Laravel's web-form redirect when a client forgets `Accept: application/json`.
 */
final class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
