<?php

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['pos.tax' => ['enabled' => false, 'rate' => '0', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    $this->seed(RoleSeeder::class);
});

it('sends cashiers straight to the till', function () {
    $cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $this->actingAs($cashier)->get(route('dashboard'))->assertRedirect(route('pos.index'));
});

it('shows warehouse staff the restock view without money figures', function () {
    $staff = User::factory()->create()->assignRole(Role::Warehouse->value);

    $this->actingAs($staff)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('overview', null)
            ->has('lowStock'));
});

it('gives the owner 30 days of sales and profit, best sellers and stock value', function () {
    Carbon::setTestNow('2026-11-22 08:00:00');
    $owner = User::factory()->create()->assignRole(Role::Owner->value);
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 5000]);
    app(StockLedger::class)->receive($product, 50, '3000');

    app(OpenShift::class)->handle($owner, 0);
    Carbon::setTestNow('2026-11-10 12:00:00');
    app(CompleteSale::class)->handle($owner, [['product_unit_id' => $unit->id, 'qty' => 2]], paidAmount: 10000);
    Carbon::setTestNow('2026-11-22 12:00:00');
    app(CompleteSale::class)->handle($owner, [['product_unit_id' => $unit->id, 'qty' => 4]], paidAmount: 20000);

    $this->actingAs($owner)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('overview.range', ['from' => '2026-10-24', 'to' => '2026-11-22'])
            ->has('overview.daily', 30)
            ->where('overview.daily.29.gross', 20000)
            ->where('overview.today.transactions', 1)
            ->where('overview.totals.gross', 30000)
            ->where('overview.totals.grossProfit', 30000 - 6 * 3000)
            ->where('overview.topProducts.0.name', 'Aqua')
            ->where('overview.topProducts.0.netQty', 6)
            ->where('overview.inventoryValue', 44 * 3000)
            ->has('lowStock'));
});
