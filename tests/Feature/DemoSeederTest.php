<?php

use App\Enums\Role;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Hash;

it('seeds demo accounts with roles and an opening stock that matches the ledger', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'owner@kasir.test')->sole()->hasRole(Role::Owner->value))->toBeTrue()
        ->and(User::where('email', 'gudang@kasir.test')->sole()->hasRole(Role::Warehouse->value))->toBeTrue()
        ->and(User::where('email', 'kasir@kasir.test')->sole()->hasRole(Role::Cashier->value))->toBeTrue()
        ->and(Hash::check(DemoUserSeeder::DEMO_OWNER_PIN, (string) User::where('email', 'owner@kasir.test')->value('pin_hash')))->toBeTrue()
        ->and(User::where('email', 'kasir@kasir.test')->value('pin_hash'))->toBeNull();

    expect(GoodsReceipt::count())->toBe(1);

    // Indomie Goreng: 5 dus × 40 = 200 pcs, at 112.000 / 40 = 2.800 per pcs
    expect(Product::where('sku', 'MIE-001')->sole())
        ->stock_on_hand->toBe(200)
        ->avg_cost->toBe('2800.0000');

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('can be re-run without duplicating stock', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(GoodsReceipt::count())->toBe(1)
        ->and(Product::where('sku', 'MIE-001')->sole()->stock_on_hand)->toBe(200);
});
