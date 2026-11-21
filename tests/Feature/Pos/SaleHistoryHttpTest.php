<?php

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SaleReturn;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['pin_hash' => Hash::make('4321')])->assignRole(Role::Owner->value);
    $this->andi = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
    $this->budi = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($product, 100, '3000');

    $sell = function (User $cashier, int $qty) {
        return app(CompleteSale::class)->handle($cashier, [['product_unit_id' => $this->unit->id, 'qty' => $qty]], paidAmount: 100000);
    };
    app(OpenShift::class)->handle($this->andi, 0);
    app(OpenShift::class)->handle($this->budi, 0);
    $this->andiSale = $sell($this->andi, 2);
    $this->budiSale = $sell($this->budi, 3);
});

it('shows cashiers only their own shift, owners everything', function () {
    $this->actingAs($this->andi)->get(route('pos.sales.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/sales/index')
            ->where('isOwner', false)
            ->has('sales.data', 1)
            ->where('sales.data.0.number', $this->andiSale->number)
            ->has('shifts', 1));

    $this->actingAs($this->owner)->get(route('pos.sales.index'))
        ->assertInertia(fn (Assert $page) => $page->where('isOwner', true)->has('sales.data', 2)->has('cashiers'));

    $this->actingAs($this->owner)->get(route('pos.sales.index', ['cashier' => $this->budi->id]))
        ->assertInertia(fn (Assert $page) => $page->has('sales.data', 1)->where('sales.data.0.cashier', 'Budi'));
});

it('lets a cashier open only their own sale', function () {
    $this->actingAs($this->andi)->get(route('pos.sales.show', $this->andiSale))
        ->assertInertia(fn (Assert $page) => $page
            ->component('pos/sales/show')
            ->where('sale.total', 8000)
            ->where('sale.items.0.paidShare', 8000)
            ->where('canReturn', true));

    $this->actingAs($this->andi)->get(route('pos.sales.show', $this->budiSale))->assertForbidden();
    $this->actingAs($this->andi)->post(route('pos.sales.returns.store', $this->budiSale), [
        'items' => [['sale_item_id' => $this->budiSale->items[0]->id, 'qty' => 1]],
        'owner_pin' => '4321',
    ])->assertForbidden();
    $this->actingAs($this->owner)->get(route('pos.sales.show', $this->budiSale))->assertOk();
});

it('processes a return over HTTP and shows it on the sale and in the drawer', function () {
    $this->actingAs($this->andi)->post(route('pos.sales.returns.store', $this->andiSale), [
        'items' => [['sale_item_id' => $this->andiSale->items[0]->id, 'qty' => 1]],
        'owner_pin' => '4321',
        'reason' => 'Bocor',
    ])->assertRedirect(route('pos.sales.show', $this->andiSale))->assertSessionHasNoErrors();

    expect(SaleReturn::sole()->total)->toBe(4000);

    $this->actingAs($this->andi)->get(route('pos.sales.show', $this->andiSale))
        ->assertInertia(fn (Assert $page) => $page
            ->has('sale.returns', 1)
            ->where('sale.returns.0.reason', 'Bocor')
            ->where('sale.items.0.returnedQty', 1));

    $this->actingAs($this->andi)->get(route('pos.sales.index'))
        ->assertInertia(fn (Assert $page) => $page->where('sales.data.0.returnedTotal', 4000));

    $this->actingAs($this->andi)->get(route('pos.shift.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shift.refundsTotal', 4000)
            ->where('shift.expectedCash', 8000 - 4000));
});

it('validates the PIN format before checking it', function () {
    $this->actingAs($this->andi)->post(route('pos.sales.returns.store', $this->andiSale), [
        'items' => [['sale_item_id' => $this->andiSale->items[0]->id, 'qty' => 1]],
        'owner_pin' => '12ab',
    ])->assertSessionHasErrors(['owner_pin' => 'PIN terdiri dari 4–6 angka.']);
});

it('prints a marked copy of the receipt', function () {
    $this->actingAs($this->andi)->get(route('pos.receipts.show', [$this->andiSale, 'copy' => 1]))
        ->assertOk()
        ->assertSee('SALINAN / CETAK ULANG');

    $this->actingAs($this->andi)->get(route('pos.receipts.show', $this->andiSale))
        ->assertOk()
        ->assertDontSee('SALINAN');
});

describe('owner PIN settings', function () {
    it('is for owners only', function () {
        $this->actingAs($this->andi)->get(route('owner-pin.edit'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('owner-pin.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('settings/pin')->where('hasPin', true));
    });

    it('sets a new PIN after the password is confirmed', function () {
        $this->actingAs($this->owner)->put(route('owner-pin.update'), [
            'current_password' => 'wrong', 'pin' => '987654', 'pin_confirmation' => '987654',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($this->owner)->put(route('owner-pin.update'), [
            'current_password' => 'password', 'pin' => '987654', 'pin_confirmation' => '987654',
        ])->assertSessionHasNoErrors();

        expect(Hash::check('987654', $this->owner->fresh()->pin_hash))->toBeTrue();
    });

    it('never sends the PIN hash to the browser', function () {
        $this->actingAs($this->owner)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('auth.user.pin_hash'));
    });
});
