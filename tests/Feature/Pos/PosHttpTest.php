<?php

use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->product = Product::factory()->create(['name' => 'Aqua 600ml', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($this->product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($this->product, 10, '3000');
});

function openShiftFor(User $user, int $cash = 100000): void
{
    test()->actingAs($user)->post(route('pos.shift.store'), ['opening_cash' => $cash])->assertRedirect(route('pos.index'));
}

describe('access', function () {
    it('lets cashiers and owners in', function (Role $role) {
        $user = User::factory()->create()->assignRole($role->value);

        $this->actingAs($user)->get(route('pos.index'))->assertOk();
    })->with([Role::Cashier, Role::Owner]);

    it('keeps warehouse staff and role-less users out', function (?Role $role) {
        $user = User::factory()->create();
        if ($role) {
            $user->assignRole($role->value);
        }

        $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    })->with([Role::Warehouse, null]);

    it('sends guests to login', function () {
        $this->get(route('pos.index'))->assertRedirect(route('login'));
    });
});

it('asks to open a shift first, then shows the till with the catalogue', function () {
    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->component('pos/open-shift'));

    openShiftFor($this->cashier);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/index')
            ->where('shift.openingCash', 100000)
            ->has('catalog', 1)
            ->where('catalog.0.name', 'Aqua 600ml')
            ->where('catalog.0.price', 4000)
            ->where('catalog.0.stock', 10)
            ->where('tax.rate', 12)
            ->where('lastSale', null));
});

it('checks out, then hands the result to the till and a printable receipt', function () {
    openShiftFor($this->cashier);

    $response = $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 3]],
        'paid_amount' => 20000,
    ]);

    $sale = Sale::sole();
    $response->assertRedirect(route('pos.index'))->assertSessionHas('last_sale_id', $sale->id);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lastSale.number', $sale->number)
            ->where('lastSale.change', 8000)
            ->where('catalog.0.stock', 7));

    $this->actingAs($this->cashier)->get(route('pos.receipts.show', $sale))
        ->assertOk()
        ->assertSeeText($sale->number)
        ->assertSeeText('Aqua 600ml')
        ->assertSeeText('12.000')     // total
        ->assertSeeText('8.000')      // change
        ->assertSeeText('PPN 12%');
});

it('reports validation and stock problems back to the form', function () {
    openShiftFor($this->cashier);

    $this->actingAs($this->cashier)->post(route('pos.sales.store'), ['items' => [], 'paid_amount' => 0])
        ->assertSessionHasErrors('items');

    $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 11]],
        'paid_amount' => 100000,
    ])->assertSessionHasErrors(['items' => 'Stok Aqua 600ml tidak cukup: diminta 11, tersedia 10.']);

    $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]],
        'paid_amount' => 3999,
    ])->assertSessionHasErrors('paid_amount');

    expect(Sale::count())->toBe(0);
});

it('hides receipts from other cashiers but not from the owner', function () {
    openShiftFor($this->cashier);
    $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]],
        'paid_amount' => 4000,
    ]);
    $sale = Sale::sole();

    $other = User::factory()->create()->assignRole(Role::Cashier->value);
    $owner = User::factory()->create()->assignRole(Role::Owner->value);

    $this->actingAs($other)->get(route('pos.receipts.show', $sale))->assertForbidden();
    $this->actingAs($owner)->get(route('pos.receipts.show', $sale))->assertOk();
});

it('holds a cart, lists it on the till, and discards it', function () {
    openShiftFor($this->cashier);

    $this->actingAs($this->cashier)->post(route('pos.held.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 2, 'discount_type' => 'amount', 'discount_value' => 500]],
        'label' => 'Pak Joko',
    ])->assertRedirect(route('pos.index'));

    $held = Sale::sole();
    expect($held->status)->toBe(SaleStatus::Held);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('heldSales', 1)
            ->where('heldSales.0.label', 'Pak Joko')
            ->where('heldSales.0.total', 7500)
            ->where('heldSales.0.items.0.discountValue', 500));

    $this->actingAs($this->cashier)->delete(route('pos.held.destroy', $held))->assertRedirect(route('pos.index'));
    expect(Sale::count())->toBe(0);
});

it('closes the shift from the summary page', function () {
    openShiftFor($this->cashier, 50000);
    $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->unit->id, 'qty' => 2]],
        'paid_amount' => 10000,
    ]);

    $this->actingAs($this->cashier)->get(route('pos.shift.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/close-shift')
            ->where('shift.expectedCash', 58000)
            ->where('heldCount', 0));

    $this->actingAs($this->cashier)->put(route('pos.shift.update'), ['counted_cash' => 58000])
        ->assertRedirect(route('pos.index'));

    expect(Shift::sole())->cash_difference->toBe(0)->closed_at->not->toBeNull();

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->component('pos/open-shift'));
});
