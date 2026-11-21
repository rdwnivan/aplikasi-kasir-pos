<?php

use App\Actions\Inventory\ReceiveGoods;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);

    // Indomie: 1 dus = 40 pcs
    $this->product = Product::factory()->create(['base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 3500]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->dus->id, 'conversion' => 40, 'price' => 130000]);

    $this->receive = app(ReceiveGoods::class);
});

it('books a receipt in base units and costs it per base unit', function () {
    $receipt = $this->receive->handle($this->user, [
        ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 2, 'unit_price' => 112000],
    ], Supplier::factory()->create()->id, Carbon::parse('2026-11-21'));

    expect($receipt)
        ->number->toBe('GR-20261121-0001')
        ->total->toBe(224000);

    expect($receipt->items->first())
        ->conversion->toBe(40)
        ->subtotal->toBe(224000);

    // 112.000 / 40 = 2.800 per pcs
    expect($this->product->fresh())
        ->stock_on_hand->toBe(80)
        ->avg_cost->toBe('2800.0000');

    $movement = StockMovement::sole();
    expect($movement)
        ->type->toBe(StockMovementType::Purchase)
        ->qty->toBe(80)
        ->reference->toBeInstanceOf(GoodsReceipt::class);
});

it('mixes units in one receipt and re-weights the average', function () {
    $this->receive->handle($this->user, [
        ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 1, 'unit_price' => 112000], // 40 @ 2800
        ['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 10, 'unit_price' => 3300],  // 10 @ 3300
    ]);

    // (40 × 2800 + 10 × 3300) / 50 = 2900
    expect($this->product->fresh())
        ->stock_on_hand->toBe(50)
        ->avg_cost->toBe('2900.0000');
});

it('stays consistent with the ledger replay when the per-unit cost does not divide evenly', function () {
    $this->receive->handle($this->user, [
        ['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 7, 'unit_price' => 3000],
        ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 3, 'unit_price' => 115000], // 2875 / pcs
        ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 1, 'unit_price' => 116666], // 2916.65 / pcs
    ]);

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('numbers receipts sequentially per day', function () {
    $line = [['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 1, 'unit_price' => 3000]];
    $day = Carbon::parse('2026-11-21');

    $first = $this->receive->handle($this->user, $line, receivedAt: $day);
    $second = $this->receive->handle($this->user, $line, receivedAt: $day);
    $nextDay = $this->receive->handle($this->user, $line, receivedAt: $day->copy()->addDay());

    expect([$first->number, $second->number, $nextDay->number])
        ->toBe(['GR-20261121-0001', 'GR-20261121-0002', 'GR-20261122-0001']);
});

it('rejects the whole receipt when any line is invalid', function (Closure $badLine, string $errorKey) {
    $good = ['product_id' => $this->product->id, 'unit_id' => $this->pcs->id, 'qty' => 5, 'unit_price' => 3000];

    try {
        $this->receive->handle($this->user, [$good, $badLine($this)]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($errorKey);
    }

    expect(GoodsReceipt::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($this->product->fresh()->stock_on_hand)->toBe(0);
})->with([
    'unit not registered for product' => [fn ($t) => ['product_id' => $t->product->id, 'unit_id' => Unit::factory()->create()->id, 'qty' => 1, 'unit_price' => 1000], 'items.1.unit_id'],
    'zero qty' => [fn ($t) => ['product_id' => $t->product->id, 'unit_id' => $t->pcs->id, 'qty' => 0, 'unit_price' => 1000], 'items.1.qty'],
    'negative price' => [fn ($t) => ['product_id' => $t->product->id, 'unit_id' => $t->pcs->id, 'qty' => 1, 'unit_price' => -1], 'items.1.unit_price'],
]);

it('rejects an empty receipt', function () {
    $this->receive->handle($this->user, []);
})->throws(ValidationException::class);
