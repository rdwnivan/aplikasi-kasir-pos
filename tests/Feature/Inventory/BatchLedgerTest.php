<?php

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Carbon;

/**
 * Batches and expiry dates in the stock ledger (DESIGN.md §19).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    $this->ledger = new StockLedger;
    $this->product = Product::factory()->create(['name' => 'Susu UHT']);
});

/** @param list<array{0: string|null, 1: int, 2?: string|null}> $parts [expires_on, qty, code] */
function batches(array $parts): array
{
    return array_map(fn (array $p) => ['expires_on' => $p[0], 'qty' => $p[1], 'code' => $p[2] ?? null], $parts);
}

function batchQty(Product $product): array
{
    return StockBatch::query()->where('product_id', $product->id)->orderBy('id')->get()
        ->mapWithKeys(fn (StockBatch $b) => [($b->expires_on?->toDateString() ?? 'tanpa').($b->code ? "/{$b->code}" : '') => $b->qty_on_hand])
        ->all();
}

describe('products without tracking', function () {
    it('books movements without a batch, exactly as before', function () {
        $this->ledger->receive($this->product, 10, '3000');
        $this->ledger->issue($this->product, 4);

        expect(StockMovement::query()->whereNotNull('stock_batch_id')->count())->toBe(0)
            ->and(StockBatch::query()->count())->toBe(0)
            ->and($this->product->fresh()->stock_on_hand)->toBe(6)
            ->and($this->ledger->sellable($this->product->fresh()))->toBe(6);
    });

    it('refuses batch details for a product that does not track expiry', function () {
        expect(fn () => $this->ledger->receive($this->product, 2, '3000', batches: batches([['2026-12-31', 2]])))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('starting and stopping tracking', function () {
    it('moves existing stock into one batch without a date, leaving stock and HPP alone', function () {
        $this->ledger->receive($this->product, 10, '3000');
        $this->ledger->startTracking($this->product);

        $product = $this->product->fresh();
        expect($product)
            ->tracks_expiry->toBeTrue()
            ->stock_on_hand->toBe(10)
            ->avg_cost->toBe('3000.0000')
            ->and(batchQty($product))->toBe(['tanpa' => 10]);

        $pair = StockMovement::query()->where('type', StockMovementType::Adjustment)->orderBy('id')->get();
        expect($pair)->toHaveCount(2)
            ->and($pair[0]->qty)->toBe(-10)
            ->and($pair[0]->stock_batch_id)->toBeNull()
            ->and($pair[1]->qty)->toBe(10)
            ->and($pair[1]->stock_batch_id)->not->toBeNull()
            ->and($pair[1]->note)->toBe('Mulai lacak kedaluwarsa');
    });

    it('only sets the flag when there is no stock', function () {
        $this->ledger->startTracking($this->product);

        expect($this->product->fresh()->tracks_expiry)->toBeTrue()
            ->and(StockMovement::query()->count())->toBe(0);
    });

    it('moves every batch back out when tracking is switched off', function () {
        $this->ledger->startTracking($this->product);
        $this->ledger->receive($this->product, 5, '3000', batches: batches([['2026-12-10', 3], ['2026-12-20', 2]]));
        $this->ledger->stopTracking($this->product);

        $product = $this->product->fresh();
        expect($product)
            ->tracks_expiry->toBeFalse()
            ->stock_on_hand->toBe(5)
            ->and(array_sum(batchQty($product)))->toBe(0)
            ->and((int) StockMovement::query()->whereNull('stock_batch_id')->sum('qty'))->toBe(5);
    });
});

describe('receiving into batches', function () {
    beforeEach(fn () => $this->ledger->startTracking($this->product));

    it('needs batch details that add up to the quantity', function () {
        expect(fn () => $this->ledger->receive($this->product, 5, '3000'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $this->ledger->receive($this->product, 5, '3000', batches: batches([['2026-12-10', 4]])))
            ->toThrow(InvalidArgumentException::class)
            ->and(StockMovement::query()->count())->toBe(0);
    });

    it('writes one movement per batch and merges the same date and code', function () {
        $this->ledger->receive($this->product, 5, '3000', batches: batches([['2026-12-10', 3], ['2026-12-20', 2]]));
        $this->ledger->receive($this->product, 4, '3600', batches: batches([['2026-12-10', 1], ['2026-12-10', 3, 'L42']]));

        $product = $this->product->fresh();
        expect(batchQty($product))->toBe(['2026-12-10' => 4, '2026-12-20' => 2, '2026-12-10/L42' => 3])
            ->and($product->stock_on_hand)->toBe(9)
            // (5 × 3000 + 4 × 3600) / 9 = 3266.6667, re-weighted per movement
            ->and($product->avg_cost)->toBe('3266.6667')
            ->and(StockMovement::query()->where('type', StockMovementType::Purchase)->count())->toBe(4);
    });
});

describe('issuing first-expired-first-out', function () {
    beforeEach(function () {
        $this->ledger->receive($this->product, 2, '3000'); // old stock, becomes the batch without a date
        $this->ledger->startTracking($this->product);
        $this->ledger->receive($this->product, 6, '3000', batches: batches([['2026-12-20', 3], ['2026-12-05', 3]]));
    });

    it('takes the batch without a date first, then the earliest date', function () {
        $this->ledger->issue($this->product, 4);

        expect(batchQty($this->product->fresh()))->toBe(['tanpa' => 0, '2026-12-20' => 3, '2026-12-05' => 1]);

        $out = StockMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->get();
        expect($out->pluck('qty')->all())->toBe([-2, -2])
            ->and($out->pluck('unit_cost')->unique()->all())->toBe(['3000.0000']);
    });

    it('never sells an expired batch and says how much is left to sell', function () {
        Carbon::setTestNow('2026-12-06 10:00:00'); // the 5 Dec batch has expired

        expect($this->ledger->sellable($this->product->fresh()))->toBe(5);

        try {
            $this->ledger->issue($this->product, 6);
            $this->fail('Expected the sale to be refused.');
        } catch (InsufficientStockException $e) {
            expect($e->available)->toBe(5)
                ->and($e->getMessage())->toBe('Stok Susu UHT yang belum kedaluwarsa tinggal 5 (3 sudah kedaluwarsa).');
        }

        $this->ledger->issue($this->product, 5);
        expect(batchQty($this->product->fresh()))->toBe(['tanpa' => 0, '2026-12-20' => 0, '2026-12-05' => 3]);
    });

    it('still sells a batch on its expiry date, and as of an earlier date when asked', function () {
        Carbon::setTestNow('2026-12-05 23:00:00');
        expect($this->ledger->sellable($this->product->fresh()))->toBe(8);

        Carbon::setTestNow('2026-12-06 00:05:00');
        $this->ledger->issue($this->product, 8, sellableOn: Carbon::parse('2026-12-05 23:55:00'));

        expect($this->product->fresh()->stock_on_hand)->toBe(0);
    });

    it('takes from a chosen batch, expired or not, but not more than it holds', function () {
        Carbon::setTestNow('2026-12-06 10:00:00');
        $expired = StockBatch::query()->whereDate('expires_on', '2026-12-05')->sole();

        $this->ledger->issue($this->product, 3, StockMovementType::Disposal, batch: $expired);
        expect($expired->fresh()->qty_on_hand)->toBe(0)
            ->and(fn () => $this->ledger->issue($this->product, 1, StockMovementType::Disposal, batch: $expired))
            ->toThrow(InsufficientStockException::class);
    });

    it('refuses a product-wide count for a tracked product', function () {
        expect(fn () => $this->ledger->adjustTo($this->product, 3))->toThrow(LogicException::class);
    });

    it('counts a batch, or a batch found during the count, at the current average', function () {
        $dated = StockBatch::query()->whereDate('expires_on', '2026-12-05')->sole();

        $this->ledger->adjustBatchTo($this->product, $dated, 1, StockMovementType::Opname);
        $this->ledger->adjustBatchTo($this->product, ['expires_on' => '2027-01-15', 'code' => null], 4, StockMovementType::Opname);

        expect(batchQty($this->product->fresh()))->toBe(['tanpa' => 2, '2026-12-20' => 3, '2026-12-05' => 1, '2027-01-15' => 4])
            ->and($this->product->fresh())->stock_on_hand->toBe(10)->avg_cost->toBe('3000.0000')
            ->and($this->ledger->adjustBatchTo($this->product, $dated, 1, StockMovementType::Opname))->toBeNull();
    });
});
