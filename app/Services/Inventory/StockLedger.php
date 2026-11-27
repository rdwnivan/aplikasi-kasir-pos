<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\ExpiredStockException;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of stock. Every change appends a row to stock_movements and updates the
 * products.stock_on_hand / products.avg_cost cache in the same transaction, under a row lock,
 * so concurrent cashiers selling the same product cannot oversell or corrupt the average.
 *
 * HPP uses the moving-average method:
 *   new_avg = (stock × avg + qty_in × cost_in) / (stock + qty_in)
 * Outgoing movements are costed at the current average and leave it unchanged.
 *
 * Products that track expiry (DESIGN.md §19) keep their stock in batches: every movement names
 * its batch, the batch's qty_on_hand cache moves with it, and stock leaves first-expired-first-out,
 * never from an expired batch unless one is named. Batch rows are only written here, after the
 * product's row lock is held, so that lock serialises them too: there is no separate batch lock.
 *
 * A batch is named either by an existing row (`['batch' => StockBatch|int, 'qty' => …]`) or by
 * its date and code (`['expires_on' => 'Y-m-d'|null, 'code' => …, 'qty' => …]`), which finds
 * the product's batch with that date and code or starts one.
 */
final class StockLedger
{
    public const TRACKING_STARTED = 'Mulai lacak kedaluwarsa';

    public const TRACKING_STOPPED = 'Berhenti lacak kedaluwarsa';

    /**
     * Stock coming in with its own cost (goods receipt, sale return). Re-weights the average.
     * A product that tracks expiry needs $batches adding up to $qty; it gets one movement per
     * batch, and the last one is returned.
     *
     * @param  string  $unitCost  Cost per BASE unit, as a decimal string.
     * @param  list<array<string, mixed>>|null  $batches
     */
    public function receive(
        Product $product,
        int $qty,
        string $unitCost,
        StockMovementType $type = StockMovementType::Purchase,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
        ?array $batches = null,
    ): StockMovement {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Received quantity must be positive.');
        }
        if (! $type->revaluesAverage()) {
            throw new InvalidArgumentException("Movement type [{$type->value}] cannot carry its own cost.");
        }
        if (Decimal::compare($unitCost, '0') < 0) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }

        // Round once, up front: the average must be computed from exactly the cost that gets
        // stored on the movement, or replaying the ledger (stock:rebuild) would drift.
        $cost = Decimal::round($unitCost);

        return DB::transaction(function () use ($product, $qty, $cost, $type, $reference, $user, $note, $batches) {
            $locked = $this->lock($product);
            $parts = $this->incomingParts($locked, $qty, $batches);

            return $this->write($product, $locked, $type, $parts, $cost, $reference, $user, $note);
        });
    }

    /**
     * Stock going out (sale, disposal). Costed at the current average. For a product that
     * tracks expiry the stock comes first-expired-first-out from batches that may still be
     * sold on $sellableOn (default today), or from $batch alone when one is named (a disposal
     * may take expired goods). Returns the last movement written; all carry the same cost.
     */
    public function issue(
        Product $product,
        int $qty,
        StockMovementType $type = StockMovementType::Sale,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
        ?CarbonInterface $sellableOn = null,
        ?StockBatch $batch = null,
    ): StockMovement {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Issued quantity must be positive.');
        }

        return DB::transaction(function () use ($product, $qty, $type, $reference, $user, $note, $sellableOn, $batch) {
            $locked = $this->lock($product);

            if (! $locked->tracks_expiry) {
                if ($batch !== null) {
                    throw new InvalidArgumentException("{$locked->name} does not track expiry.");
                }
                $parts = [[null, -$qty]];
            } elseif ($batch !== null) {
                $parts = [[$this->ownBatch($locked, $batch->id), -$qty]];
            } else {
                $parts = $this->fefo($locked, $qty, $sellableOn ?? now());
            }

            return $this->write($product, $locked, $type, $parts, null, $reference, $user, $note);
        });
    }

    /**
     * Set stock to a physically counted quantity (stock opname). Records the difference,
     * costed at the current average. Returns null when there is no difference. A product that
     * tracks expiry is counted per batch instead (adjustBatchTo()).
     */
    public function adjustTo(
        Product $product,
        int $countedQty,
        StockMovementType $type = StockMovementType::Opname,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
    ): ?StockMovement {
        if ($countedQty < 0) {
            throw new InvalidArgumentException('Counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($product, $countedQty, $type, $reference, $user, $note) {
            $locked = $this->lock($product);
            if ($locked->tracks_expiry) {
                throw new LogicException("{$locked->name} tracks expiry: count it per batch.");
            }

            $difference = $countedQty - $locked->stock_on_hand;
            if ($difference === 0) {
                return null;
            }

            return $this->write($product, $locked, $type, [[null, $difference]], null, $reference, $user, $note);
        });
    }

    /**
     * Set one batch of a product that tracks expiry to a counted quantity, at the current
     * average. $batch may name a batch found during the count (date + code). Returns null
     * when there is no difference.
     *
     * @param  StockBatch|array<string, mixed>  $batch
     */
    public function adjustBatchTo(
        Product $product,
        StockBatch|array $batch,
        int $countedQty,
        StockMovementType $type = StockMovementType::Opname,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
    ): ?StockMovement {
        if ($countedQty < 0) {
            throw new InvalidArgumentException('Counted quantity cannot be negative.');
        }

        return DB::transaction(function () use ($product, $batch, $countedQty, $type, $reference, $user, $note) {
            $locked = $this->lock($product);
            if (! $locked->tracks_expiry) {
                throw new LogicException("{$locked->name} does not track expiry.");
            }

            $row = $batch instanceof StockBatch
                ? $this->ownBatch($locked, $batch->id)
                : $this->findBatch($locked, $batch);
            $difference = $countedQty - ($row->qty_on_hand ?? 0);

            if ($difference === 0) {
                return null;
            }

            $row ??= $this->startBatch($locked, $batch);

            return $this->write($product, $locked, $type, [[$row, $difference]], null, $reference, $user, $note);
        });
    }

    /**
     * Switch expiry tracking on. Stock already on hand moves into one batch without a date
     * through a pair of adjustments at the current average, so stock and HPP stay the same.
     */
    public function startTracking(Product $product, ?User $user = null): void
    {
        DB::transaction(function () use ($product, $user) {
            $locked = $this->lock($product);
            if ($locked->tracks_expiry) {
                return;
            }

            $locked->forceFill(['tracks_expiry' => true])->save();
            $stock = $locked->stock_on_hand;

            if ($stock !== 0) {
                $undated = $this->findBatch($locked, ['expires_on' => null, 'code' => null]) ?? $this->startBatch($locked, ['expires_on' => null, 'code' => null]);
                $this->write($product, $locked, StockMovementType::Adjustment, [[null, -$stock], [$undated, $stock]], null, null, $user, self::TRACKING_STARTED);
            }

            $product->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /** Switch expiry tracking off: every batch's stock moves back out of batches. */
    public function stopTracking(Product $product, ?User $user = null): void
    {
        DB::transaction(function () use ($product, $user) {
            $locked = $this->lock($product);
            if (! $locked->tracks_expiry) {
                return;
            }

            $parts = [];
            $total = 0;
            foreach ($locked->batches()->where('qty_on_hand', '!=', 0)->orderBy('id')->get() as $batch) {
                $parts[] = [$batch, -$batch->qty_on_hand];
                $total += $batch->qty_on_hand;
            }
            if ($parts !== []) {
                $parts[] = [null, $total];
                $this->write($product, $locked, StockMovementType::Adjustment, $parts, null, null, $user, self::TRACKING_STOPPED);
            }

            $locked->forceFill(['tracks_expiry' => false])->save();
            $product->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /**
     * Stock that may be sold on $on: all of it, or for a product that tracks expiry only the
     * batches that have not expired. A read for screens and early checks; the issue itself
     * decides under the lock.
     */
    public function sellable(Product $product, ?CarbonInterface $on = null): int
    {
        if (! $product->tracks_expiry) {
            return $product->stock_on_hand;
        }

        return (int) $product->batches()->where('qty_on_hand', '>', 0)->sellableOn($on ?? now())->sum('qty_on_hand');
    }

    /**
     * Moving-average re-weighting. When there is no stock on hand (or the books are negative
     * from a past correction) the incoming cost simply becomes the new average.
     */
    public static function reweigh(int $stockBefore, string $avgBefore, int $qtyIn, string $costIn): string
    {
        if ($stockBefore <= 0) {
            return Decimal::round($costIn);
        }

        $value = Decimal::add(
            Decimal::mul((string) $stockBefore, $avgBefore),
            Decimal::mul((string) $qtyIn, $costIn),
        );

        return Decimal::round(Decimal::div($value, (string) ($stockBefore + $qtyIn)));
    }

    /**
     * @param  list<array<string, mixed>>|null  $batches
     * @return list<array{0: StockBatch|array<string, mixed>|null, 1: int}>
     */
    private function incomingParts(Product $locked, int $qty, ?array $batches): array
    {
        if (! $locked->tracks_expiry) {
            if ($batches !== null) {
                throw new InvalidArgumentException("{$locked->name} does not track expiry.");
            }

            return [[null, $qty]];
        }

        if ($batches === null || $batches === []) {
            throw new InvalidArgumentException("{$locked->name} tracks expiry: say which batches the stock goes into.");
        }

        $sum = 0;
        $parts = [];
        foreach ($batches as $spec) {
            $part = (int) ($spec['qty'] ?? 0);
            if ($part <= 0) {
                throw new InvalidArgumentException('Batch quantities must be positive.');
            }
            $sum += $part;
            $parts[] = [$spec, $part];
        }
        if ($sum !== $qty) {
            throw new InvalidArgumentException("Batch quantities add up to {$sum}, not {$qty}.");
        }

        return $parts;
    }

    /** @return list<array{0: StockBatch, 1: int}> */
    private function fefo(Product $locked, int $qty, CarbonInterface $on): array
    {
        $open = $locked->batches()->where('qty_on_hand', '>', 0);
        $sellable = (clone $open)->sellableOn($on)->fefo()->get();
        $available = (int) $sellable->sum('qty_on_hand');

        if ($available < $qty) {
            $expired = (int) $open->sum('qty_on_hand') - $available;

            throw $expired > 0
                ? new ExpiredStockException($locked, $qty, $available, $expired)
                : new InsufficientStockException($locked, $qty, $available);
        }

        $parts = [];
        $left = $qty;
        foreach ($sellable as $batch) {
            $take = min($left, $batch->qty_on_hand);
            $parts[] = [$batch, -$take];
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        return $parts;
    }

    /**
     * Writes the movements, keeping the product (and batch) caches in step. Batches named by
     * date and code are resolved here, one part at a time, so two parts naming the same new
     * batch end up in one row.
     *
     * @param  list<array{0: StockBatch|array<string, mixed>|null, 1: int}>  $parts
     */
    private function write(
        Product $product,
        Product $locked,
        StockMovementType $type,
        array $parts,
        ?string $incomingCost,
        ?Model $reference,
        ?User $user,
        ?string $note,
    ): StockMovement {
        $movement = null;

        foreach ($parts as [$target, $signedQty]) {
            $batch = match (true) {
                $target === null => null,
                $target instanceof StockBatch => $this->ownBatch($locked, $target->id),
                isset($target['batch']) => $this->ownBatch($locked, $target['batch'] instanceof StockBatch ? $target['batch']->id : (int) $target['batch']),
                default => $this->findBatch($locked, $target) ?? $this->startBatch($locked, $target),
            };

            $stockBefore = $locked->stock_on_hand;
            $avgBefore = (string) $locked->avg_cost;
            $stockAfter = $stockBefore + $signedQty;

            if ($stockAfter < 0) {
                throw new InsufficientStockException($locked, abs($signedQty), $stockBefore);
            }
            if ($batch !== null && $batch->qty_on_hand + $signedQty < 0) {
                throw new InsufficientStockException($locked, abs($signedQty), $batch->qty_on_hand);
            }

            [$unitCost, $avgAfter] = $incomingCost === null
                ? [$avgBefore, $avgBefore]
                : [$incomingCost, self::reweigh($stockBefore, $avgBefore, $signedQty, $incomingCost)];

            $locked->forceFill([
                'stock_on_hand' => $stockAfter,
                'avg_cost' => $avgAfter,
            ])->save();

            $batch?->forceFill(['qty_on_hand' => $batch->qty_on_hand + $signedQty])->save();

            $movement = StockMovement::create([
                'product_id' => $locked->id,
                'stock_batch_id' => $batch?->id,
                'type' => $type,
                'qty' => $signedQty,
                'unit_cost' => Decimal::round($unitCost),
                'stock_after' => $stockAfter,
                'avg_cost_after' => $avgAfter,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => $user?->getKey(),
                'note' => $note,
            ]);
        }

        // Keep the caller's instance in sync with what was persisted.
        $product->setRawAttributes($locked->getAttributes(), sync: true);

        return $movement ?? throw new LogicException('Nothing to book.');
    }

    /** A fresh read of one of the locked product's batches (safe: the product lock is held). */
    private function ownBatch(Product $locked, int $batchId): StockBatch
    {
        $batch = StockBatch::query()->whereKey($batchId)->where('product_id', $locked->id)->first();

        return $batch ?? throw new InvalidArgumentException("Batch {$batchId} does not belong to {$locked->name}.");
    }

    /** @param array<string, mixed> $spec */
    private function findBatch(Product $locked, array $spec): ?StockBatch
    {
        [$expiresOn, $code] = self::batchKey($spec);

        return StockBatch::query()
            ->where('product_id', $locked->id)
            ->when($expiresOn === null, fn ($q) => $q->whereNull('expires_on'), fn ($q) => $q->whereDate('expires_on', $expiresOn))
            ->when($code === null, fn ($q) => $q->whereNull('code'), fn ($q) => $q->where('code', $code))
            ->orderBy('id')
            ->first();
    }

    /** @param array<string, mixed> $spec */
    private function startBatch(Product $locked, array $spec): StockBatch
    {
        [$expiresOn, $code] = self::batchKey($spec);

        return StockBatch::create([
            'product_id' => $locked->id,
            'expires_on' => $expiresOn,
            'code' => $code,
            'qty_on_hand' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: string|null, 1: string|null}
     */
    private static function batchKey(array $spec): array
    {
        $expiresOn = $spec['expires_on'] ?? null;
        $code = isset($spec['code']) ? trim((string) $spec['code']) : '';

        return [
            $expiresOn === null || $expiresOn === '' ? null : substr((string) $expiresOn, 0, 10),
            $code === '' ? null : $code,
        ];
    }

    private function lock(Product $product): Product
    {
        return Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }
}
