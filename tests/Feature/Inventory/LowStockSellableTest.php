<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * "Stok menipis" counts what may still be sold: expired batches that were not disposed of yet
 * do not hide a product that is running low (DESIGN.md §19).
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    $ledger = app(StockLedger::class);
    $pcs = Unit::factory()->create(['name' => 'pcs']);

    $product = function (string $name, int $minStock) use ($pcs): Product {
        $product = Product::factory()->create(['name' => $name, 'base_unit_id' => $pcs->id, 'min_stock' => $minStock]);
        ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 15000]);

        return $product;
    };
    $batches = fn (array $parts) => array_map(fn (array $p) => ['expires_on' => $p[0], 'qty' => $p[1], 'code' => null], $parts);

    // Received a week ago, when every batch was still good.
    Carbon::setTestNow('2026-11-20 10:00:00');

    // 40 on hand, but 30 expired on 25 Nov: only 10 may be sold, at the minimum of 10.
    $this->roti = $product('Roti Tawar', 10);
    $ledger->startTracking($this->roti);
    $ledger->receive($this->roti, 40, '12000', batches: $batches([['2026-11-25', 30], ['2026-12-10', 10]]));

    // 40 on hand, 5 expired: 35 may be sold, well above the minimum of 10.
    $this->susu = $product('Susu UHT', 10);
    $ledger->startTracking($this->susu);
    $ledger->receive($this->susu, 40, '5000', batches: $batches([['2026-11-25', 5], ['2026-12-31', 35]]));

    // Expires today: still sellable today, so 20 against a minimum of 10.
    $this->yogurt = $product('Yogurt', 10);
    $ledger->startTracking($this->yogurt);
    $ledger->receive($this->yogurt, 20, '8000', batches: $batches([['2026-11-27', 20]]));

    // No expiry tracking: on hand is what may be sold, 8 against a minimum of 10.
    $this->gula = $product('Gula', 10);
    $ledger->receive($this->gula, 8, '14000');

    Carbon::setTestNow('2026-11-27 10:00:00');
});

it('lists products whose sellable stock is at or below the minimum on the dashboard', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lowStock.count', 2)
            ->has('lowStock.items', 2)
            ->where('lowStock.items.0.name', 'Gula')
            ->where('lowStock.items.0.stock', 8)
            ->where('lowStock.items.1.name', 'Roti Tawar')
            ->where('lowStock.items.1.stock', 10));
});

it('counts the same products for the sidebar badge', function () {
    $this->actingAs($this->owner)->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('lowStockCount', 2));
});

it('filters the product list by sellable stock and shows both figures', function () {
    $this->actingAs($this->owner)->get(route('products.index', ['status' => 'low']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('products.data', 2)
            ->where('products.data.0.name', 'Gula')
            ->where('products.data.0.stock', 8)
            ->where('products.data.0.sellableStock', 8)
            ->where('products.data.1.name', 'Roti Tawar')
            ->where('products.data.1.stock', 40)
            ->where('products.data.1.sellableStock', 10));

    $this->actingAs($this->owner)->get(route('products.index', ['q' => 'Susu']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('products.data.0.stock', 40)
            ->where('products.data.0.sellableStock', 35));
});

it('sizes the low-stock purchase draft from sellable stock', function () {
    // Twice the minimum is 20: Roti needs 20 − 10 sellable = 10, Gula 20 − 8 = 12.
    $staff = User::factory()->create()->assignRole(Role::Warehouse->value);

    $this->actingAs($staff)->get(route('purchase-orders.create', ['dari' => 'stok-minimum']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('suggested', 2)
            ->where('suggested.0.product_id', $this->gula->id)
            ->where('suggested.0.qty', 12)
            ->where('suggested.1.product_id', $this->roti->id)
            ->where('suggested.1.qty', 10));
});
