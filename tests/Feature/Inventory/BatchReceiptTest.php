<?php

use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\Role;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Goods receipts (direct and from a purchase order) for products that track expiry (DESIGN.md §19 P4).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->user = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->supplier = Supplier::factory()->create();
    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);

    $this->susu = Product::factory()->create(['name' => 'Susu UHT', 'base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->susu)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 6000]);
    ProductUnit::factory()->for($this->susu)->create(['unit_id' => $this->dus->id, 'conversion' => 24, 'price' => 130000]);
    app(StockLedger::class)->startTracking($this->susu);

    $this->gula = Product::factory()->create(['name' => 'Gula', 'base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->gula)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 18500]);
});

function susuLine(object $test, int $dus, ?array $batches): array
{
    return array_filter([
        'product_id' => $test->susu->id,
        'unit_id' => $test->dus->id,
        'qty' => $dus,
        'unit_price' => 120000,
        'batches' => $batches,
    ], fn ($v) => $v !== null);
}

it('splits a line into batches counted in the line unit, booked in base units', function () {
    $receipt = app(ReceiveGoods::class)->handle($this->user, [
        susuLine($this, 2, [['expires_on' => '2026-12-10', 'qty' => 1], ['expires_on' => '2026-12-24', 'qty' => 1, 'code' => 'L7']]),
        ['product_id' => $this->gula->id, 'unit_id' => $this->pcs->id, 'qty' => 5, 'unit_price' => 14700],
    ], $this->supplier->id);

    expect(StockBatch::query()->orderBy('expires_on')->get()->map(fn ($b) => [$b->expires_on->toDateString(), $b->code, $b->qty_on_hand])->all())
        ->toBe([['2026-12-10', null, 24], ['2026-12-24', 'L7', 24]])
        ->and($this->susu->fresh())->stock_on_hand->toBe(48)->avg_cost->toBe('5000.0000')
        ->and($this->gula->fresh()->stock_on_hand)->toBe(5)
        ->and($receipt->total)->toBe(240000 + 73500);
});

it('refuses a tracked line without dates, dates that do not add up, or a date already past', function (?array $batches, string $message) {
    try {
        app(ReceiveGoods::class)->handle($this->user, [susuLine($this, 2, $batches)]);
        $this->fail('Expected the receipt to be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('items.0.batches')
            ->and($e->errors()['items.0.batches'][0])->toBe($message);
    }

    expect(GoodsReceipt::query()->count())->toBe(0)->and(StockBatch::query()->count())->toBe(0);
})->with([
    'no dates' => [null, 'Isi tanggal kedaluwarsa untuk Susu UHT.'],
    'not adding up' => [[['expires_on' => '2026-12-10', 'qty' => 1]], 'Jumlah per tanggal (1) harus sama dengan jumlah barang (2).'],
    'past date' => [[['expires_on' => '2026-11-26', 'qty' => 2]], 'Tanggal kedaluwarsa 26 Nov 2026 sudah lewat.'],
]);

it('refuses dates for a product that does not track expiry', function () {
    expect(fn () => app(ReceiveGoods::class)->handle($this->user, [
        ['product_id' => $this->gula->id, 'unit_id' => $this->pcs->id, 'qty' => 5, 'unit_price' => 14700, 'batches' => [['expires_on' => '2026-12-10', 'qty' => 5]]],
    ]))->toThrow(ValidationException::class, 'Gula tidak melacak tanggal kedaluwarsa.');
});

it('receives a purchase order line into batches, still capped at what is outstanding', function () {
    $po = app(SavePurchaseOrder::class)->handle($this->user, ['supplier_id' => $this->supplier->id, 'expected_at' => '2026-11-28', 'note' => null], [
        ['product_id' => $this->susu->id, 'unit_id' => $this->dus->id, 'qty' => 3, 'unit_price' => 120000],
    ]);
    $po = app(OrderPurchaseOrder::class)->handle($po, $this->user);
    $item = $po->items->sole();

    expect(fn () => app(ReceivePurchaseOrder::class)->handle($po, $this->user, [
        ['purchase_order_item_id' => $item->id, 'qty' => 2, 'unit_price' => 120000],
    ]))->toThrow(ValidationException::class, 'Isi tanggal kedaluwarsa untuk Susu UHT.');

    app(ReceivePurchaseOrder::class)->handle($po, $this->user, [
        ['purchase_order_item_id' => $item->id, 'qty' => 2, 'unit_price' => 120000, 'batches' => [['expires_on' => '2026-12-31', 'qty' => 2]]],
    ]);

    expect(StockBatch::query()->sole()->qty_on_hand)->toBe(48);
});

it('takes dates over HTTP and shows the batches on the receipt', function () {
    $this->actingAs($this->user)->post(route('goods-receipts.store'), [
        'supplier_id' => $this->supplier->id,
        'received_at' => '2026-11-27',
        'items' => [susuLine($this, 1, [['expires_on' => '2026-12-10', 'qty' => 1, 'code' => 'A1']])],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $receipt = GoodsReceipt::query()->sole();

    $this->actingAs($this->user)->get(route('goods-receipts.show', $receipt))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.items.0.tracksExpiry', true)
            ->where('receipt.items.0.batches.0.expiresOn', '2026-12-10')
            ->where('receipt.items.0.batches.0.code', 'A1')
            ->where('receipt.items.0.batches.0.qty', 24));

    $this->actingAs($this->user)->get(route('goods-receipts.create'))
        ->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => collect($products)->firstWhere('name', 'Susu UHT')['tracksExpiry'] === true));
});
