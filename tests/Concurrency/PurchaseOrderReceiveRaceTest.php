<?php

use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
| Two clerks book the same outstanding delivery at the same moment. The purchase order row
| lock makes the second one re-read what is left after the first commits.
*/

it('lets only one of two concurrent receipts take the rest of an order', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    [$siti, $joko] = User::factory()->count(2)->create();

    $po = app(SavePurchaseOrder::class)->handle(
        $siti,
        ['supplier_id' => Supplier::factory()->create()->id, 'expected_at' => null, 'note' => null],
        [['product_id' => $product->id, 'unit_id' => $pcs->id, 'qty' => 10, 'unit_price' => 1000]],
    );
    $po = app(OrderPurchaseOrder::class)->handle($po, $siti);
    $item = $po->items->first();

    DB::beginTransaction();
    app(ReceivePurchaseOrder::class)->handle($po, $siti, [
        ['purchase_order_item_id' => $item->id, 'qty' => 10, 'unit_price' => 1000],
    ]);

    $jokoProcess = startPhpProcess('concurrent_po_receive.php', [(string) $joko->id, (string) $po->id, (string) $item->id, '10']);

    usleep(1_500_000);
    expect(proc_get_status($jokoProcess['handle'])['running'])->toBeTrue('Joko should be waiting on the order lock.');

    DB::commit();

    $result = finishProcess($jokoProcess);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toBe('validation')
        ->and(GoodsReceipt::query()->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(10)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Received);
});
