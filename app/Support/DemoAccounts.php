<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The public demo publishes the passwords of the seeded accounts, so with
 * `pos.protect_demo_accounts` on, nobody may lock those accounts or change how they log in.
 */
final class DemoAccounts
{
    public const MESSAGE = 'Akun demo tidak bisa diubah di demo publik. Buat akun baru untuk mencobanya.';

    public static function isProtected(User $user): bool
    {
        if (! Config::boolean('pos.protect_demo_accounts')) {
            return false;
        }

        return in_array($user->email, array_column(DemoUserSeeder::ACCOUNTS, 'email'), true);
    }

    /**
     * Undo a 2FA or passkey a visitor added to a protected account (before the routes were
     * guarded, see `ProtectDemoAccountSignIn`), so it signs in with just its password again.
     * Called once the password has matched.
     */
    public static function releaseSignIn(User $user): void
    {
        if (! self::isProtected($user)) {
            return;
        }

        DB::transaction(function () use ($user) {
            if ($user->two_factor_secret !== null || $user->two_factor_confirmed_at !== null) {
                $user->forceFill([
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ])->save();
            }

            $user->passkeys()->delete();
        });
    }
}
