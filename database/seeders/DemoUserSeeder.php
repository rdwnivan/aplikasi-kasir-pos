<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Public demo accounts, one per role. All use the password "password" (documented in the
 * README) — never run this seeder against a production database with real data.
 */
class DemoUserSeeder extends Seeder
{
    public const ACCOUNTS = [
        ['name' => 'Budi Santoso', 'email' => 'owner@kasir.test', 'role' => Role::Owner],
        ['name' => 'Siti Rahmawati', 'email' => 'gudang@kasir.test', 'role' => Role::Warehouse],
        ['name' => 'Andi Pratama', 'email' => 'kasir@kasir.test', 'role' => Role::Cashier],
    ];

    /** Documented in the README next to the demo accounts. */
    public const DEMO_OWNER_PIN = '1234';

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$account['role']->value]);

            // The owner's approval PIN for returns, so the demo works out of the box.
            if ($account['role'] === Role::Owner && $user->pin_hash === null) {
                $user->forceFill(['pin_hash' => Hash::make(self::DEMO_OWNER_PIN)])->save();
            }
        }
    }
}
