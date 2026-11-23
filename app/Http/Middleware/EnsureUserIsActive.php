<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account an owner deactivated, whichever way it got in (a session
 * that was already open, a remember-me cookie, a passkey).
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'Akun ini dinonaktifkan. Hubungi owner.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->deactivated_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
        }

        return $next($request);
    }
}
