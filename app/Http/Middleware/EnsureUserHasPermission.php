<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserHasPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission, string $scope = 'any'): Response
    {
        $user = $request->user();

        $allowed = match ($scope) {
            'global' => $user !== null && $user->hasGlobalPermission($permission),
            default => $user !== null && $user->hasPermission($permission),
        };

        abort_unless($allowed, 403, 'Anda tidak memiliki hak akses untuk halaman ini.');

        return $next($request);
    }
}
