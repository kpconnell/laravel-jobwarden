<?php

declare(strict_types=1);

namespace JobWarden\Http\Middleware;

use Closure;
use JobWarden\JobWarden;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the operator API — delegates to the JobWarden::auth() callback
 * (defaults to local environment only). Returns 403 when not authorized.
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(JobWarden::check($request), 403, 'Forbidden (jobwarden operator API).');

        return $next($request);
    }
}
