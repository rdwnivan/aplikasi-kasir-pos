<?php

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;

/*
| Two cashiers sell the last unit at the same moment, on two real database connections.
| Only PostgreSQL has the row locks this is about (SQLite serialises whole-database writes
| and the suite runs it in memory), so the test skips elsewhere.
*/

it('lets only one of two concurrent checkouts take the last unit', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($product, 1, '3000');

    [$andi, $budi] = User::factory()->count(2)->create();
    app(OpenShift::class)->handle($andi, 0);
    app(OpenShift::class)->handle($budi, 0);

    // Andi's checkout runs inside a transaction we keep open, so his lock on the product
    // row is held while Budi's process tries to sell the same unit.
    DB::beginTransaction();
    app(CompleteSale::class)->handle($andi, [['product_unit_id' => $unit->id, 'qty' => 1]], paidAmount: 4000);

    $budiProcess = startCheckoutProcess($budi->id, $unit->id);

    usleep(1_500_000);
    $status = proc_get_status($budiProcess['handle']);
    expect($status['running'])->toBeTrue('Budi should be waiting on the row lock, not finished.');

    DB::commit();

    $result = finishProcess($budiProcess);

    expect($result)->toBe(['ok' => false, 'error' => 'insufficient_stock'])
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and(Sale::query()->count())->toBe(1)
        ->and(Sale::query()->sole()->user_id)->toBe($andi->id)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(1);
});

it('never oversells when the other writer is not a checkout', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    // Two checkouts also queue on the invoice counter row, which would hide a missing product
    // lock. A stock opname taking the last unit shares nothing with a sale but the product
    // row, so here only StockLedger's SELECT ... FOR UPDATE stands between us and an oversell.
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    $ledger = app(StockLedger::class);
    $ledger->receive($product, 1, '3000');
    $budi = User::factory()->create();
    app(OpenShift::class)->handle($budi, 0);

    DB::beginTransaction();
    $ledger->adjustTo($product, 0, StockMovementType::Opname); // counted: the shelf is empty

    $budiProcess = startCheckoutProcess($budi->id, $unit->id);
    usleep(1_500_000);
    expect(proc_get_status($budiProcess['handle'])['running'])->toBeTrue('The sale should wait for the opname.');

    DB::commit();

    expect(finishProcess($budiProcess))->toBe(['ok' => false, 'error' => 'insufficient_stock'])
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and((int) StockMovement::query()->where('product_id', $product->id)->sum('qty'))->toBe(0);
});

it('lets two concurrent checkouts both succeed when there is enough stock', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($product, 2, '3000');

    [$andi, $budi] = User::factory()->count(2)->create();
    app(OpenShift::class)->handle($andi, 0);
    app(OpenShift::class)->handle($budi, 0);

    $first = startCheckoutProcess($andi->id, $unit->id);
    $second = startCheckoutProcess($budi->id, $unit->id);

    expect(finishProcess($first)['ok'])->toBeTrue()
        ->and(finishProcess($second)['ok'])->toBeTrue()
        ->and($product->fresh()->stock_on_hand)->toBe(0);

    // The cached stock still agrees with the ledger after the race.
    $this->artisan('stock:rebuild')->assertSuccessful();
});

/** @return array{handle: resource, pipes: array<int, resource>} */
function startCheckoutProcess(int $userId, int $unitId): array
{
    return startPhpProcess('concurrent_checkout.php', [(string) $userId, (string) $unitId]);
}
