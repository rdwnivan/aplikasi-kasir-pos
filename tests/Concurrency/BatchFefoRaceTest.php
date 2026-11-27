<?php

use App\Actions\Inventory\DisposeStock;
use App\Actions\Sales\OpenShift;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| Batches are guarded by the product's row lock, not a lock of their own (DESIGN.md §19 P7).
| The other writer here is a disposal, which shares nothing with a checkout but the product
| (its DSP number has its own counter row), so only StockLedger's SELECT ... FOR UPDATE on the
| product keeps the two apart. Two checkouts would also queue on the INV counter and hide a
| missing lock (the lesson of DESIGN.md §17.6).
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->susu = Product::factory()->create(['name' => 'Susu UHT', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($this->susu)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 6000]);
    $this->ledger = app(StockLedger::class);
    $this->ledger->startTracking($this->susu);

    $this->staff = User::factory()->create();
    $this->cashier = User::factory()->create();
    app(OpenShift::class)->handle($this->cashier, 0);
});

it('sends a checkout to the next batch when a disposal takes the earliest one first', function () {
    $this->ledger->receive($this->susu, 6, '5000', batches: [
        ['expires_on' => now()->addDays(3)->toDateString(), 'qty' => 1],
        ['expires_on' => now()->addDays(20)->toDateString(), 'qty' => 5],
    ]);
    $early = StockBatch::query()->orderBy('expires_on')->firstOrFail();
    $late = StockBatch::query()->orderByDesc('expires_on')->firstOrFail();

    // The disposal holds the product lock while the checkout wants the same earliest unit.
    DB::beginTransaction();
    app(DisposeStock::class)->handle($this->staff, [['stock_batch_id' => $early->id, 'qty' => 1]], 'Kemasan penyok');

    $checkout = startPhpProcess('concurrent_checkout.php', [(string) $this->cashier->id, (string) $this->unit->id]);
    usleep(1_500_000);
    expect(proc_get_status($checkout['handle'])['running'])->toBeTrue('The checkout should wait for the disposal.');

    DB::commit();

    expect(finishProcess($checkout)['ok'])->toBeTrue()
        ->and($early->fresh()->qty_on_hand)->toBe(0)
        ->and($late->fresh()->qty_on_hand)->toBe(4)
        ->and($this->susu->fresh()->stock_on_hand)->toBe(4)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->sole()->stock_batch_id)->toBe($late->id);

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('lets only one of a disposal and a checkout take the last sellable units', function () {
    Carbon::setTestNow(now()->subDays(10));
    $this->ledger->receive($this->susu, 3, '5000', batches: [
        ['expires_on' => now()->addDays(5)->toDateString(), 'qty' => 1], // expired by today
        ['expires_on' => now()->addDays(30)->toDateString(), 'qty' => 2],
    ]);
    Carbon::setTestNow();
    $sellable = StockBatch::query()->orderByDesc('expires_on')->firstOrFail();

    DB::beginTransaction();
    app(DisposeStock::class)->handle($this->staff, [['stock_batch_id' => $sellable->id, 'qty' => 2]], 'Rusak');

    $checkout = startPhpProcess('concurrent_checkout.php', [(string) $this->cashier->id, (string) $this->unit->id]);
    usleep(1_500_000);
    expect(proc_get_status($checkout['handle'])['running'])->toBeTrue('The checkout should wait for the disposal.');

    DB::commit();

    expect(finishProcess($checkout))->toBe(['ok' => false, 'error' => 'insufficient_stock'])
        ->and(Sale::query()->count())->toBe(0)
        ->and($sellable->fresh()->qty_on_hand)->toBe(0)
        ->and($this->susu->fresh()->stock_on_hand)->toBe(1); // only the expired unit is left

    $this->artisan('stock:rebuild')->assertSuccessful();
});
