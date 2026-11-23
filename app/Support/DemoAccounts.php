<?php

namespace App\Support;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Config;

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
}
