<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);
    $this->category = Category::factory()->create(['name' => 'Minuman']);
});

function productPayload(array $overrides = []): array
{
    return array_replace([
        'sku' => 'mnm-001',
        'name' => 'Aqua 600ml',
        'category_id' => test()->category->id,
        'base_unit_id' => test()->pcs->id,
        'min_stock' => 24,
        'is_active' => true,
        'units' => [
            ['unit_id' => test()->pcs->id, 'conversion' => 1, 'price' => 4000, 'barcode' => '8990000000011'],
            ['unit_id' => test()->dus->id, 'conversion' => 24, 'price' => 85000, 'barcode' => ''],
        ],
    ], $overrides);
}

describe('access', function () {
    it('is open to owner and warehouse staff', function (Role $role) {
        $user = User::factory()->create()->assignRole($role->value);

        $this->actingAs($user)->get(route('products.index'))->assertOk();
        $this->actingAs($user)->get(route('categories.index'))->assertOk();
        $this->actingAs($user)->get(route('units.index'))->assertOk();
        $this->actingAs($user)->get(route('suppliers.index'))->assertOk();
    })->with([Role::Owner, Role::Warehouse]);

    it('is closed to cashiers', function () {
        $cashier = User::factory()->create()->assignRole(Role::Cashier->value);

        $this->actingAs($cashier)->get(route('products.index'))->assertForbidden();
        $this->actingAs($cashier)->post(route('products.store'), productPayload())->assertForbidden();
    });
});

it('creates a product with tiered units, normalising SKU and empty barcodes', function () {
    $this->actingAs($this->staff)
        ->post(route('products.store'), productPayload())
        ->assertRedirect(route('products.index'))
        ->assertSessionHasNoErrors();

    $product = Product::sole();
    expect($product)
        ->sku->toBe('MNM-001')
        ->stock_on_hand->toBe(0)
        ->min_stock->toBe(24);

    $units = $product->units()->orderBy('conversion')->get();
    expect($units)->toHaveCount(2)
        ->and($units[0]->only(['unit_id', 'conversion', 'price', 'barcode']))
        ->toBe(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 4000, 'barcode' => '8990000000011'])
        ->and($units[1]->barcode)->toBeNull()
        ->and($units[1]->conversion)->toBe(24);
});

it('requires a conversion-1 row for the base unit', function (array $units, string $errorKey) {
    $payload = productPayload(['units' => array_map(fn ($u) => [...$u, 'unit_id' => $u['unit_id'] === 'pcs' ? $this->pcs->id : $this->dus->id], $units)]);

    $this->actingAs($this->staff)
        ->post(route('products.store'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(Product::count())->toBe(0);
})->with([
    'base unit missing' => [[['unit_id' => 'dus', 'conversion' => 24, 'price' => 85000]], 'units'],
    'base conversion not 1' => [[['unit_id' => 'pcs', 'conversion' => 2, 'price' => 4000]], 'units.0.conversion'],
    'other unit with conversion 1' => [[['unit_id' => 'pcs', 'conversion' => 1, 'price' => 4000], ['unit_id' => 'dus', 'conversion' => 1, 'price' => 4000]], 'units.1.conversion'],
    'same unit twice' => [[['unit_id' => 'pcs', 'conversion' => 1, 'price' => 4000], ['unit_id' => 'pcs', 'conversion' => 1, 'price' => 4000]], 'units.1.unit_id'],
]);

it('refuses a barcode or SKU another product already uses', function () {
    $other = Product::factory()->create(['sku' => 'MNM-001']);
    ProductUnit::factory()->for($other)->create(['barcode' => '8990000000011']);

    $this->actingAs($this->staff)
        ->post(route('products.store'), productPayload())
        ->assertSessionHasErrors(['sku', 'units.0.barcode']);
});

it('updates units in place: changes prices, moves a barcode between its own units, drops removed units', function () {
    $this->actingAs($this->staff)->post(route('products.store'), productPayload());
    $product = Product::sole();
    $pcsRowId = $product->units()->where('unit_id', $this->pcs->id)->value('id');

    $this->actingAs($this->staff)
        ->put(route('products.update', $product), productPayload([
            'units' => [
                ['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 4500, 'barcode' => ''],
                ['unit_id' => $this->dus->id, 'conversion' => 24, 'price' => 90000, 'barcode' => '8990000000011'],
            ],
        ]))
        ->assertSessionHasNoErrors();

    $units = $product->units()->get()->keyBy('unit_id');
    expect($units[$this->pcs->id])->id->toBe($pcsRowId)->price->toBe(4500)->barcode->toBeNull()
        ->and($units[$this->dus->id])->barcode->toBe('8990000000011');

    $this->actingAs($this->staff)
        ->put(route('products.update', $product), productPayload([
            'units' => [['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 4500, 'barcode' => '']],
        ]))
        ->assertSessionHasNoErrors();

    expect($product->units()->count())->toBe(1);
});

it('locks the base unit once the product has stock history', function () {
    $this->actingAs($this->staff)->post(route('products.store'), productPayload());
    $product = Product::sole();
    app(StockLedger::class)->receive($product, 10, '3000');

    $this->actingAs($this->staff)
        ->put(route('products.update', $product), productPayload([
            'base_unit_id' => $this->dus->id,
            'units' => [['unit_id' => $this->dus->id, 'conversion' => 1, 'price' => 85000, 'barcode' => '']],
        ]))
        ->assertSessionHasErrors('base_unit_id');

    expect($product->fresh()->base_unit_id)->toBe($this->pcs->id);
});

it('never lets the form write stock or HPP', function () {
    $this->actingAs($this->staff)->post(route('products.store'), productPayload([
        'stock_on_hand' => 999,
        'avg_cost' => '1',
    ]));

    expect(Product::sole())->stock_on_hand->toBe(0)->avg_cost->toBe('0.0000');
});

describe('delete', function () {
    it('deletes a product without history for good', function () {
        $this->actingAs($this->staff)->post(route('products.store'), productPayload());
        $product = Product::sole();

        $this->actingAs($this->staff)->delete(route('products.destroy', $product))->assertRedirect();

        expect(Product::withTrashed()->count())->toBe(0)
            ->and(ProductUnit::count())->toBe(0);
    });

    it('archives a product with history instead, keeping its ledger', function () {
        $this->actingAs($this->staff)->post(route('products.store'), productPayload());
        $product = Product::sole();
        $ledger = app(StockLedger::class);
        $ledger->receive($product, 5, '3000');
        $ledger->issue($product, 5);

        $this->actingAs($this->staff)->delete(route('products.destroy', $product))->assertRedirect();

        $archived = Product::withTrashed()->sole();
        expect($archived->trashed())->toBeTrue()
            ->and($archived->is_active)->toBeFalse()
            ->and($archived->stockMovements()->count())->toBe(2);

        $this->actingAs($this->staff)->get(route('products.index', ['status' => 'archived']))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.archived', true));

        $this->actingAs($this->staff)->post(route('products.restore', $archived))->assertRedirect();
        expect($archived->fresh())->trashed()->toBeFalse()->is_active->toBeTrue();
    });

    it('refuses to archive a product that still has stock', function () {
        $this->actingAs($this->staff)->post(route('products.store'), productPayload());
        $product = Product::sole();
        app(StockLedger::class)->receive($product, 5, '3000');

        $this->actingAs($this->staff)->delete(route('products.destroy', $product))->assertSessionHasErrors('product');

        expect($product->fresh()->trashed())->toBeFalse();
    });
});

it('lists, searches and filters products', function () {
    $this->actingAs($this->staff)->post(route('products.store'), productPayload());
    $this->actingAs($this->staff)->post(route('products.store'), productPayload([
        'sku' => 'MIE-001', 'name' => 'Indomie Goreng', 'category_id' => null, 'min_stock' => 0,
        'units' => [['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 3500, 'barcode' => '']],
    ]));

    $this->actingAs($this->staff)->get(route('products.index', ['q' => 'indomie']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products/index')
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Indomie Goreng'));

    $this->actingAs($this->staff)->get(route('products.index', ['q' => '8990000000011']))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.sku', 'MNM-001'));

    // Aqua: stock 0 ≤ min 24 → low. Indomie: min 0, stock 0 → also "low" (0 ≤ 0).
    $this->actingAs($this->staff)->get(route('products.index', ['category' => $this->category->id]))
        ->assertInertia(fn (Assert $page) => $page->has('products.data', 1));
});

it('shows the stock card with running balance and document links', function () {
    $this->actingAs($this->staff)->post(route('products.store'), productPayload());
    $product = Product::sole();
    $ledger = app(StockLedger::class);
    $ledger->receive($product, 24, '3000');
    $ledger->issue($product, 4);

    $this->actingAs($this->staff)->get(route('products.stock-card', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/products/stock-card')
            ->where('product.stock', 20)
            ->has('movements.data', 2)
            ->where('movements.data.0.qty', -4)
            ->where('movements.data.0.stockAfter', 20)
            ->where('movements.data.1.type', 'purchase')
            ->where('movements.data.1.stockAfter', 24));
});

it('renders the create and edit forms', function () {
    $this->actingAs($this->staff)->get(route('products.create'))
        ->assertInertia(fn (Assert $page) => $page->component('catalog/products/form')->where('product', null)->has('units', 2));

    $this->actingAs($this->staff)->post(route('products.store'), productPayload());
    $product = Product::sole();

    $this->actingAs($this->staff)->get(route('products.edit', $product))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.sku', 'MNM-001')
            ->where('product.hasHistory', false)
            ->has('product.units', 2));
});
