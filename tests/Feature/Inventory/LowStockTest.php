<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $ledger = app(StockLedger::class);

    $this->empty = Product::factory()->create(['name' => 'Gula', 'min_stock' => 10]);
    $this->below = Product::factory()->create(['name' => 'Aqua', 'min_stock' => 24]);
    $ledger->receive($this->below, 5, '3000');
    $this->atMin = Product::factory()->create(['name' => 'Teh', 'min_stock' => 12]);
    $ledger->receive($this->atMin, 12, '4000');
    $healthy = Product::factory()->create(['name' => 'Kopi', 'min_stock' => 5]);
    $ledger->receive($healthy, 50, '1000');

    // Not something to reorder.
    Product::factory()->create(['name' => 'Nonaktif', 'min_stock' => 10, 'is_active' => false]);
    Product::factory()->create(['name' => 'Arsip', 'min_stock' => 10])->delete();
});

it('counts low-stock products for the sidebar badge, for staff who restock only', function (Role $role, ?int $expected) {
    $user = User::factory()->create()->assignRole($role->value);

    // Settings pages render for every role, so the shared prop can be checked anywhere.
    $this->actingAs($user)->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('lowStockCount', $expected));
})->with([
    'owner' => [Role::Owner, 3],
    'warehouse' => [Role::Warehouse, 3],
    'cashier' => [Role::Cashier, null],
]);

it('lists low-stock products on the dashboard, emptiest first', function () {
    $owner = User::factory()->create()->assignRole(Role::Owner->value);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('lowStock.count', 3)
            ->has('lowStock.items', 3)
            ->where('lowStock.items.0.name', 'Gula')
            ->where('lowStock.items.0.stock', 0)
            ->where('lowStock.items.1.name', 'Aqua')
            ->where('lowStock.items.2.name', 'Teh'));
});

it('filters the product list to low stock', function () {
    $owner = User::factory()->create()->assignRole(Role::Owner->value);

    $this->actingAs($owner)->get(route('products.index', ['status' => 'low']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 3));
});
