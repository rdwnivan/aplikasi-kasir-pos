<?php

use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\ClosePurchaseOrder;
use App\Actions\Purchasing\DeletePurchaseOrder;
use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 09:00:00');
    $this->user = User::factory()->create();
    $this->supplier = Supplier::factory()->create();
    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);

    // Indomie: 1 dus = 40 pcs. Aqua: pcs only.
    $this->indomie = Product::factory()->create(['base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->indomie)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 3500]);
    ProductUnit::factory()->for($this->indomie)->create(['unit_id' => $this->dus->id, 'conversion' => 40, 'price' => 130000]);
    $this->aqua = Product::factory()->create(['base_unit_id' => $this->pcs->id]);
    ProductUnit::factory()->for($this->aqua)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 4000]);

    $this->draft = fn (array $items = []) => app(SavePurchaseOrder::class)->handle(
        $this->user,
        ['supplier_id' => $this->supplier->id, 'expected_at' => '2026-11-26', 'note' => null],
        $items ?: [
            ['product_id' => $this->indomie->id, 'unit_id' => $this->dus->id, 'qty' => 10, 'unit_price' => 112000],
            ['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 24, 'unit_price' => 3000],
        ],
    );
    $this->ordered = fn () => app(OrderPurchaseOrder::class)->handle(($this->draft)(), $this->user);

    /** @param array<int, int> $qtyByItem purchase_order_item_id => qty */
    $this->receive = fn (PurchaseOrder $po, array $qtyByItem, array $prices = []) => app(ReceivePurchaseOrder::class)->handle(
        $po,
        $this->user,
        array_map(fn (int $itemId, int $qty) => [
            'purchase_order_item_id' => $itemId,
            'qty' => $qty,
            'unit_price' => $prices[$itemId] ?? $po->items->firstWhere('id', $itemId)?->unit_price ?? 1000,
        ], array_keys($qtyByItem), $qtyByItem),
    );
});

it('saves a draft without a number, totals it and moves no stock', function () {
    $po = ($this->draft)();

    expect($po)
        ->status->toBe(PurchaseOrderStatus::Draft)
        ->number->toBeNull()
        ->total->toBe(10 * 112000 + 24 * 3000)
        ->supplier_id->toBe($this->supplier->id)
        ->and($po->expected_at->toDateString())->toBe('2026-11-26')
        ->and($po->items)->toHaveCount(2)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('replaces the lines of a draft when it is edited', function () {
    $po = ($this->draft)();

    app(SavePurchaseOrder::class)->handle(
        $this->user,
        ['supplier_id' => $this->supplier->id, 'expected_at' => null, 'note' => 'Revisi'],
        [['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 48, 'unit_price' => 2900]],
        $po,
    );

    $po->refresh();
    expect($po)
        ->total->toBe(48 * 2900)
        ->note->toBe('Revisi')
        ->expected_at->toBeNull()
        ->and($po->items)->toHaveCount(1)
        ->and($po->items->first()->qty)->toBe(48);
});

it('rejects a unit the product does not have and the same product unit twice', function () {
    expect(fn () => ($this->draft)([
        ['product_id' => $this->aqua->id, 'unit_id' => $this->dus->id, 'qty' => 1, 'unit_price' => 1000],
    ]))->toThrow(ValidationException::class, 'Satuan tidak terdaftar untuk produk ini.');

    expect(fn () => ($this->draft)([
        ['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 1, 'unit_price' => 1000],
        ['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 2, 'unit_price' => 1000],
    ]))->toThrow(ValidationException::class, 'Produk dan satuan ini sudah ada di baris lain.');

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('numbers the order when it is placed and locks its lines', function () {
    $po = ($this->ordered)();

    expect($po)
        ->status->toBe(PurchaseOrderStatus::Ordered)
        ->number->toBe('PO-20261123-0001')
        ->and($po->ordered_at->toDateTimeString())->toBe('2026-11-23 09:00:00');

    expect(fn () => app(SavePurchaseOrder::class)->handle(
        $this->user,
        ['supplier_id' => $this->supplier->id, 'expected_at' => null, 'note' => null],
        [['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 1, 'unit_price' => 1]],
        $po,
    ))->toThrow(ValidationException::class, 'Hanya draft yang bisa diubah.');

    expect(fn () => app(OrderPurchaseOrder::class)->handle($po, $this->user))
        ->toThrow(ValidationException::class);
    expect(fn () => app(DeletePurchaseOrder::class)->handle($po))
        ->toThrow(ValidationException::class, 'Hanya draft yang bisa dihapus.');
});

it('deletes a draft', function () {
    $po = ($this->draft)();

    app(DeletePurchaseOrder::class)->handle($po);

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('receives part of an order, then the rest', function () {
    $po = ($this->ordered)();
    [$noodles, $water] = $po->items->all();

    $first = ($this->receive)($po, [$noodles->id => 4, $water->id => 24]);

    expect($first)->toBeInstanceOf(GoodsReceipt::class)
        ->number->toBe('GR-20261123-0001')
        ->purchase_order_id->toBe($po->id)
        ->supplier_id->toBe($this->supplier->id)
        ->and($first->items->pluck('purchase_order_item_id')->all())->toBe([$noodles->id, $water->id])
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Partial)
        ->and($this->indomie->fresh()->stock_on_hand)->toBe(160)
        ->and($this->aqua->fresh()->stock_on_hand)->toBe(24);

    // A line already in full is simply left out of the next delivery.
    ($this->receive)($po->fresh(), [$noodles->id => 6]);

    $po->refresh();
    expect($po->status)->toBe(PurchaseOrderStatus::Received)
        ->and($po->finished_at)->not->toBeNull()
        ->and($po->receivedQuantities())->toBe([$noodles->id => 10, $water->id => 24])
        ->and($po->goodsReceipts()->count())->toBe(2)
        ->and($this->indomie->fresh()->stock_on_hand)->toBe(400);
});

it('costs stock at the invoice price, not the price agreed on the order', function () {
    $po = ($this->ordered)();
    $noodles = $po->items->first();

    ($this->receive)($po, [$noodles->id => 2], [$noodles->id => 116000]);

    // 116.000 / 40 = 2.900 per pcs
    expect($this->indomie->fresh()->avg_cost)->toBe('2900.0000');
    expect(StockMovement::sole())
        ->type->toBe(StockMovementType::Purchase)
        ->qty->toBe(80);

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('refuses to receive more than is still outstanding, and books nothing', function () {
    $po = ($this->ordered)();
    $noodles = $po->items->first();
    ($this->receive)($po, [$noodles->id => 8]);

    expect(fn () => ($this->receive)($po->fresh(), [$noodles->id => 3]))
        ->toThrow(ValidationException::class, 'Melebihi sisa pesanan (2).');

    expect(GoodsReceipt::query()->count())->toBe(1)
        ->and($this->indomie->fresh()->stock_on_hand)->toBe(320);
});

it('refuses lines from another order and a delivery of nothing', function () {
    $po = ($this->ordered)();
    $other = ($this->ordered)();

    expect(fn () => ($this->receive)($po, [$other->items->first()->id => 1]))
        ->toThrow(ValidationException::class, 'Barang ini tidak ada di pesanan.');

    expect(fn () => ($this->receive)($po, [$po->items->first()->id => 0]))
        ->toThrow(ValidationException::class, 'Isi jumlah yang diterima untuk minimal satu barang.');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('receives only orders that are placed and not finished', function () {
    $draft = ($this->draft)();
    expect(fn () => ($this->receive)($draft, [$draft->items->first()->id => 1]))
        ->toThrow(ValidationException::class, 'tidak bisa menerima barang');

    $cancelled = app(CancelPurchaseOrder::class)->handle(($this->ordered)(), $this->user, 'Supplier tutup');
    expect(fn () => ($this->receive)($cancelled, [$cancelled->items->first()->id => 1]))
        ->toThrow(ValidationException::class, 'tidak bisa menerima barang');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('closes the rest of a partly received order with a reason', function () {
    $po = ($this->ordered)();
    ($this->receive)($po, [$po->items->first()->id => 4]);

    expect(fn () => app(ClosePurchaseOrder::class)->handle($po->fresh(), $this->user, '  '))
        ->toThrow(ValidationException::class, 'Alasan wajib diisi.');

    $closed = app(ClosePurchaseOrder::class)->handle($po->fresh(), $this->user, 'Stok supplier habis');

    expect($closed)
        ->status->toBe(PurchaseOrderStatus::Closed)
        ->close_reason->toBe('Stok supplier habis')
        ->finished_at->not->toBeNull();
});

it('only closes orders that have received something, and only cancels those that have not', function () {
    $po = ($this->ordered)();

    expect(fn () => app(ClosePurchaseOrder::class)->handle($po, $this->user, 'x'))
        ->toThrow(ValidationException::class, 'Belum ada barang yang diterima; batalkan saja pesanannya.');

    ($this->receive)($po, [$po->items->first()->id => 1]);

    expect(fn () => app(CancelPurchaseOrder::class)->handle($po->fresh(), $this->user, 'x'))
        ->toThrow(ValidationException::class, 'Sudah ada barang yang diterima; tutup sisa pesanannya.');

    $cancelled = app(CancelPurchaseOrder::class)->handle(($this->ordered)(), $this->user, 'Salah supplier');
    expect($cancelled)
        ->status->toBe(PurchaseOrderStatus::Cancelled)
        ->close_reason->toBe('Salah supplier');
});

it('leaves direct receipts without an order untouched', function () {
    $receipt = app(ReceiveGoods::class)->handle($this->user, [
        ['product_id' => $this->aqua->id, 'unit_id' => $this->pcs->id, 'qty' => 5, 'unit_price' => 3000],
    ]);

    expect($receipt->purchase_order_id)->toBeNull()
        ->and($receipt->items->first()->purchase_order_item_id)->toBeNull();
});
