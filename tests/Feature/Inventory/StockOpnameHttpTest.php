<?php

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->ledger = app(StockLedger::class);

    $this->aqua = Product::factory()->create(['name' => 'Aqua']);
    $this->teh = Product::factory()->create(['name' => 'Teh Botol']);
    $this->ledger->receive($this->aqua, 24, '3000');
    $this->ledger->receive($this->teh, 12, '4000');
});

function draftOpname(array $counts): StockOpname
{
    test()->actingAs(test()->staff)->post(route('stock-opnames.store'), [
        'note' => 'Akhir bulan',
        'items' => collect($counts)->map(fn ($qty, $id) => ['product_id' => $id, 'counted_qty' => $qty])->values()->all(),
    ])->assertSessionHasNoErrors();

    return StockOpname::query()->latest('id')->firstOrFail();
}

it('saves a draft, shows live differences, and leaves stock alone', function () {
    $opname = draftOpname([$this->aqua->id => 20, $this->teh->id => 12]);

    expect($opname->status)->toBe(StockOpname::STATUS_DRAFT)
        ->and($this->aqua->fresh()->stock_on_hand)->toBe(24);

    $this->actingAs($this->staff)->get(route('stock-opnames.show', $opname))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/stock-opnames/show')
            ->where('opname.status', 'draft')
            ->has('opname.items', 2)
            ->where('opname.items.0.product', 'Aqua')
            ->where('opname.items.0.systemQty', 24)
            ->where('opname.items.0.difference', -4)
            ->where('opname.items.0.value', -12000)
            ->where('opname.items.1.difference', 0));
});

it('edits a draft, then posts it into the ledger and freezes it', function () {
    $opname = draftOpname([$this->aqua->id => 20]);

    $this->actingAs($this->staff)->get(route('stock-opnames.edit', $opname))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/stock-opnames/form')
            ->where("opname.counts.{$this->aqua->id}", 20));

    $this->actingAs($this->staff)->put(route('stock-opnames.update', $opname), [
        'items' => [
            ['product_id' => $this->aqua->id, 'counted_qty' => 22],
            ['product_id' => $this->teh->id, 'counted_qty' => 13],
        ],
    ])->assertRedirect(route('stock-opnames.show', $opname));

    expect($opname->items()->count())->toBe(2);

    $this->actingAs($this->staff)->post(route('stock-opnames.post', $opname))
        ->assertRedirect(route('stock-opnames.show', $opname));

    expect($opname->fresh()->status)->toBe(StockOpname::STATUS_POSTED)
        ->and($this->aqua->fresh()->stock_on_hand)->toBe(22)
        ->and($this->teh->fresh()->stock_on_hand)->toBe(13)
        ->and($opname->stockMovements()->where('type', StockMovementType::Opname)->count())->toBe(2);

    $this->actingAs($this->staff)->get(route('stock-opnames.show', $opname))
        ->assertInertia(fn (Assert $page) => $page
            ->where('opname.status', 'posted')
            ->where('opname.items.0.systemQty', 24)
            ->where('opname.items.0.difference', -2)
            ->where('opname.items.0.value', -6000)
            ->where('opname.items.1.difference', 1)
            ->where('opname.items.1.value', 4000));

    // Posted documents are history.
    $this->actingAs($this->staff)->post(route('stock-opnames.post', $opname))->assertSessionHasErrors('opname');
    $this->actingAs($this->staff)->put(route('stock-opnames.update', $opname), [
        'items' => [['product_id' => $this->aqua->id, 'counted_qty' => 1]],
    ])->assertSessionHasErrors('opname');
    $this->actingAs($this->staff)->delete(route('stock-opnames.destroy', $opname))->assertSessionHasErrors('opname');
    $this->actingAs($this->staff)->get(route('stock-opnames.edit', $opname))->assertRedirect(route('stock-opnames.show', $opname));

    expect($this->aqua->fresh()->stock_on_hand)->toBe(22);
});

it('deletes a draft', function () {
    $opname = draftOpname([$this->aqua->id => 1]);

    $this->actingAs($this->staff)->delete(route('stock-opnames.destroy', $opname))->assertRedirect(route('stock-opnames.index'));

    expect(StockOpname::count())->toBe(0)
        ->and($this->aqua->fresh()->stock_on_hand)->toBe(24);
});

it('rejects duplicate products and negative counts', function () {
    $this->actingAs($this->staff)->post(route('stock-opnames.store'), [
        'items' => [
            ['product_id' => $this->aqua->id, 'counted_qty' => 1],
            ['product_id' => $this->aqua->id, 'counted_qty' => 2],
        ],
    ])->assertSessionHasErrors('items.1.product_id');

    $this->actingAs($this->staff)->post(route('stock-opnames.store'), [
        'items' => [['product_id' => $this->aqua->id, 'counted_qty' => -1]],
    ])->assertSessionHasErrors('items.0.counted_qty');

    expect(StockOpname::count())->toBe(0);
});

it('lists opnames by status', function () {
    $draft = draftOpname([$this->aqua->id => 24]);
    $posted = draftOpname([$this->teh->id => 12]);
    $this->actingAs($this->staff)->post(route('stock-opnames.post', $posted));

    $this->actingAs($this->staff)->get(route('stock-opnames.index'))
        ->assertInertia(fn (Assert $page) => $page->component('inventory/stock-opnames/index')->has('opnames.data', 2));

    $this->actingAs($this->staff)->get(route('stock-opnames.index', ['status' => 'draft']))
        ->assertInertia(fn (Assert $page) => $page->has('opnames.data', 1)->where('opnames.data.0.id', $draft->id));
});
