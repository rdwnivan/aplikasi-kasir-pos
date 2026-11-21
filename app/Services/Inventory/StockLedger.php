<?php

namespace App\Services\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of stock. Every change appends a row to stock_movements and updates the
 * products.stock_on_hand / products.avg_cost cache in the same transaction, under a row lock,
 * so concurrent cashiers selling the same product cannot oversell or corrupt the average.
 *
 * HPP uses the moving-average method:
 *   new_avg = (stock × avg + qty_in × cost_in) / (stock + qty_in)
 * Outgoing movements are costed at the current average and leave it unchanged.
 */
final class StockLedger
{
    /**
     * Stock coming in with its own cost (goods receipt, sale return). Re-weights the average.
     *
     * @param  string  $unitCost  Cost per BASE unit, as a decimal string.
     */
    public function receive(
        Product $product,
        int $qty,
        string $unitCost,
        StockMovementType $type = StockMovementType::Purchase,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
    ): StockMovement {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Received quantity must be positive.');
        }
        if (! $type->revaluesAverage()) {
            throw new InvalidArgumentException("Movement type [{$type->value}] cannot carry its own cost.");
        }
        if (bccomp($unitCost, '0', Decimal::SCALE) < 0) {
            throw new InvalidArgumentException('Unit cost cannot be negative.');
        }

        // Round once, up front: the average must be computed from exactly the cost that gets
        // stored on the movement, or replaying the ledger (stock:rebuild) would drift.
        return $this->apply($product, $type, $qty, Decimal::round($unitCost), $reference, $user, $note);
    }

    /** Stock going out (sale). Costed at the current average. */
    public function issue(
        Product $product,
        int $qty,
        StockMovementType $type = StockMovementType::Sale,
        ?Model $reference = null,
        ?User $user = null,
        ?string $note = null,
    ): StockMovement {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Issued quantity must be positive.');
        }

        return $this->apply($product, $type, -$qty, null, $reference, $user, $note);
    }

    /**
     * Set stock to a physically counted quantity (stock opname). Records the difference,
     * costed at the current average. Returns null when there is no difference.
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
            $difference = $countedQty - $locked->stock_on_hand;

            if ($difference === 0) {
                return null;
            }

            return $this->apply($product, $type, $difference, null, $reference, $user, $note);
        });
    }

    private function apply(
        Product $product,
        StockMovementType $type,
        int $signedQty,
        ?string $incomingCost,
        ?Model $reference,
        ?User $user,
        ?string $note,
    ): StockMovement {
        return DB::transaction(function () use ($product, $type, $signedQty, $incomingCost, $reference, $user, $note) {
            $locked = $this->lock($product);

            $stockBefore = $locked->stock_on_hand;
            $avgBefore = (string) $locked->avg_cost;
            $stockAfter = $stockBefore + $signedQty;

            if ($stockAfter < 0) {
                throw new InsufficientStockException($locked, abs($signedQty), $stockBefore);
            }

            [$unitCost, $avgAfter] = $incomingCost === null
                ? [$avgBefore, $avgBefore]
                : [$incomingCost, self::reweigh($stockBefore, $avgBefore, $signedQty, $incomingCost)];

            $locked->forceFill([
                'stock_on_hand' => $stockAfter,
                'avg_cost' => $avgAfter,
            ])->save();

            $movement = StockMovement::create([
                'product_id' => $locked->id,
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

            // Keep the caller's instance in sync with what was persisted.
            $product->setRawAttributes($locked->getAttributes(), sync: true);

            return $movement;
        });
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

    private function lock(Product $product): Product
    {
        return Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
    }
}
