<?php

namespace App\Http\Middleware;

use App\Support\DemoAccounts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA and passkeys are managed by vendor routes (Fortify, Laravel Passkeys), all in the `web`
 * group: on the public demo, a protected demo account may neither add nor remove either, so a
 * visitor cannot lock it for the next one (DESIGN.md §12.4.1). Runs before the routes' own
 * `password.confirm`, so the visitor is not asked for the password only to be turned away.
 */
class ProtectDemoAccountSignIn
{
    private const TWO_FACTOR = [
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    private const PASSKEYS = [
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $request->routeIs(...self::TWO_FACTOR, ...self::PASSKEYS) || ! DemoAccounts::isProtected($user)) {
            return $next($request);
        }

        // Passkey registration is a fetch() from @laravel/passkeys, which shows `message`.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            abort(403, DemoAccounts::MESSAGE);
        }

        throw ValidationException::withMessages([
            $request->routeIs(...self::TWO_FACTOR) ? 'two_factor' : 'passkey' => DemoAccounts::MESSAGE,
        ]);
    }
}
