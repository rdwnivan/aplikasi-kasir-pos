<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After an owner set someone's password (a new account or a reset), that user can do nothing
 * but choose their own password, or log out.
 */
class RequirePasswordChange
{
    private const ALLOWED = ['forced-password.edit', 'forced-password.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && ! $request->routeIs(...self::ALLOWED)) {
            return redirect()->route('forced-password.edit');
        }

        return $next($request);
    }
}
