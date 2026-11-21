<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

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

    public function run(): void
    {
        foreach (self::ACCOUNTS as $account) {
            $user = User::query()->firstOrCreate(
                ['email' => $account['email']],
                User::factory()->raw(['name' => $account['name'], 'email' => $account['email']]),
            );

            $user->syncRoles([$account['role']->value]);
        }
    }
}
