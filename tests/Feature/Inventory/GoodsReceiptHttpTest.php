<?php

use App\Enums\Role;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-21 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Warehouse->value);
    $this->supplier = Supplier::factory()->create(['name' => 'CV Sumber Rejeki']);

    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);
    $this->product = Product::factory()->create(['name' => 'Indomie Goreng', 'base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 3500]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->dus->id, 'conversion' => 40, 'price' => 125000]);
});

it('keeps cashiers out', function () {
    $cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $this->actingAs($cashier)->get(route('goods-receipts.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('stock-opnames.index'))->assertForbidden();
});

it('shows the receive form with active products and their units', function () {
    Product::factory()->create(['is_active' => false]);

    $this->actingAs($this->staff)->get(route('goods-receipts.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/goods-receipts/create')
            ->has('products', 1)
            ->where('products.0.name', 'Indomie Goreng')
            ->has('products.0.units', 2)
            ->where('today', '2026-11-21'));
});

it('receives goods in mixed units, books base-unit stock and shows the document', function () {
    $response = $this->actingAs($this->staff)->post(route('goods-receipts.store'), [
        'supplier_id' => $this->supplier->id,
        'received_at' => '2026-11-20',
        'note' => 'Faktur 123',
        'items' => [
            ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 2, 'unit_price' => 112000],
            ['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 10, 'unit_price' => 2900],
        ],
    ]);

    $receipt = GoodsReceipt::sole();
    $response->assertRedirect(route('goods-receipts.show', $receipt));

    expect($receipt)
        ->number->toBe('GR-20261120-0001')
        ->total->toBe(253000)
        ->supplier_id->toBe($this->supplier->id)
        ->and($this->product->fresh())
        ->stock_on_hand->toBe(90)
        // (80 × 2800 + 10 × 2900) / 90
        ->avg_cost->toBe('2811.1111');

    $this->actingAs($this->staff)->get(route('goods-receipts.show', $receipt))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/goods-receipts/show')
            ->where('receipt.number', 'GR-20261120-0001')
            ->where('receipt.supplier', 'CV Sumber Rejeki')
            ->where('receipt.user', 'Siti')
            ->has('receipt.items', 2)
            ->where('receipt.items.0.unit', 'dus')
            ->where('receipt.items.0.subtotal', 224000));

    $this->actingAs($this->staff)->get(route('goods-receipts.index', ['from' => '2026-11-01', 'to' => '2026-11-21']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/goods-receipts/index')
            ->has('receipts.data', 1)
            ->where('receipts.data.0.itemsCount', 2)
            ->where('filters.from', '2026-11-01'));

    $this->actingAs($this->staff)->get(route('goods-receipts.index', ['from' => '2026-11-21', 'to' => '2026-11-21']))
        ->assertInertia(fn (Assert $page) => $page->has('receipts.data', 0));
});

it('rejects future dates, unknown units and empty documents without touching stock', function () {
    $this->actingAs($this->staff)->post(route('goods-receipts.store'), [
        'received_at' => '2026-11-22',
        'items' => [['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 1, 'unit_price' => 1000]],
    ])->assertSessionHasErrors('received_at');

    $other = Unit::factory()->create();
    $this->actingAs($this->staff)->post(route('goods-receipts.store'), [
        'received_at' => '2026-11-21',
        'items' => [['product_id' => $this->product->id, 'unit_id' => $other->id, 'qty' => 1, 'unit_price' => 1000]],
    ])->assertSessionHasErrors('items.0.unit_id');

    $this->actingAs($this->staff)->post(route('goods-receipts.store'), [
        'received_at' => '2026-11-21',
        'items' => [],
    ])->assertSessionHasErrors('items');

    expect(GoodsReceipt::count())->toBe(0)
        ->and($this->product->fresh()->stock_on_hand)->toBe(0);
});
