<?php

use App\Actions\Inventory\DisposeStock;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockDisposal;
use App\Models\StockMovement;
use App\Models\User;
use App\Reports\SalesSummary;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Throwing expired goods away (DESIGN.md §19 P6c).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-12-02 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $this->ledger = app(StockLedger::class);

    $this->roti = Product::factory()->create(['name' => 'Roti Tawar']);
    $this->ledger->startTracking($this->roti);
    Carbon::setTestNow('2026-11-27 10:00:00');
    $this->ledger->receive($this->roti, 8, '12000', batches: [
        ['expires_on' => '2026-12-01', 'qty' => 3],
        ['expires_on' => '2026-12-08', 'qty' => 5],
    ]);
    Carbon::setTestNow('2026-12-02 10:00:00');
    $this->expired = StockBatch::query()->whereDate('expires_on', '2026-12-01')->sole();
    $this->fresh = StockBatch::query()->whereDate('expires_on', '2026-12-08')->sole();
});

it('throws a batch away at the average HPP, with a numbered document and a reason', function () {
    $disposal = app(DisposeStock::class)->handle($this->staff, [['stock_batch_id' => $this->expired->id, 'qty' => 3]], 'Lewat tanggal');

    expect($disposal)
        ->number->toBe('DSP-20261202-0001')
        ->reason->toBe('Lewat tanggal')
        ->total_value->toBe(36_000)
        ->and($disposal->items->sole())->qty->toBe(3)->unit_cost->toBe('12000.0000')->value->toBe(36_000)
        ->and($this->expired->fresh()->qty_on_hand)->toBe(0)
        ->and($this->roti->fresh()->stock_on_hand)->toBe(5)
        ->and(StockMovement::query()->where('type', StockMovementType::Disposal)->sole())
        ->qty->toBe(-3)->stock_batch_id->toBe($this->expired->id)->reference_id->toBe($disposal->id);

    // A loss on the shelf, not a sale: gross profit does not move.
    expect(SalesSummary::build(DateRange::of('2026-11-01', '2026-12-31'))['totals']['grossProfit'])->toBe(0);
    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('refuses more than the batch holds, no reason, or nothing at all', function () {
    expect(fn () => app(DisposeStock::class)->handle($this->staff, [['stock_batch_id' => $this->expired->id, 'qty' => 4]], 'Lewat tanggal'))
        ->toThrow(ValidationException::class, 'Batch ini tinggal 3.')
        ->and(fn () => app(DisposeStock::class)->handle($this->staff, [['stock_batch_id' => $this->expired->id, 'qty' => 1]], ' '))
        ->toThrow(ValidationException::class, 'Alasan wajib diisi.')
        ->and(fn () => app(DisposeStock::class)->handle($this->staff, [], 'Lewat tanggal'))
        ->toThrow(ValidationException::class)
        ->and(StockDisposal::query()->count())->toBe(0)
        ->and($this->expired->fresh()->qty_on_hand)->toBe(3);
});

it('fills the form with every expired batch, and lets warehouse staff but not cashiers throw goods away', function () {
    $this->actingAs($this->staff)->get(route('stock-disposals.create', ['kedaluwarsa' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('inventory/stock-disposals/create')
            ->where('lines.0.batchId', $this->expired->id)
            ->where('lines.0.qty', 3)
            ->count('lines', 1)
            ->where('batches', fn ($batches) => collect($batches)->pluck('id')->sort()->values()->all() === [$this->expired->id, $this->fresh->id]));

    $this->actingAs($this->staff)->post(route('stock-disposals.store'), [
        'reason' => 'Lewat tanggal',
        'items' => [['stock_batch_id' => $this->expired->id, 'qty' => 2]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $disposal = StockDisposal::query()->sole();
    $this->actingAs($this->staff)->get(route('stock-disposals.show', $disposal))
        ->assertInertia(fn (Assert $page) => $page->where('disposal.number', 'DSP-20261202-0001')->where('disposal.items.0.qty', 2));
    $this->actingAs($this->staff)->get(route('stock-disposals.index'))->assertOk();

    $this->actingAs($this->cashier)->get(route('stock-disposals.index'))->assertForbidden();
    $this->actingAs($this->cashier)->post(route('stock-disposals.store'), ['reason' => 'x', 'items' => [['stock_batch_id' => $this->expired->id, 'qty' => 1]]])->assertForbidden();
});
