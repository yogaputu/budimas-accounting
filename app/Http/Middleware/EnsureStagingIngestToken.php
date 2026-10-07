<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureStagingIngestToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = config('accounting.staging.ingest.enabled') === true;
        $expectedToken = config('accounting.staging.ingest.token');

        if (! $enabled || ! is_string($expectedToken) || trim($expectedToken) === '') {
            abort(503, 'Ingest staging belum diaktifkan.');
        }

        $providedToken = $request->header('X-Accounting-Staging-Token');

        if (! is_string($providedToken) || ! hash_equals($expectedToken, $providedToken)) {
            abort(401, 'Token worker staging tidak valid.');
        }

        return $next($request);
    }
}
