<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Replays the stock ledger from the first movement and compares the result with the
 * products.stock_on_hand / avg_cost cache. `--fix` rewrites the cache from the ledger.
 */
#[Signature('stock:rebuild {--fix : Overwrite the product cache with the replayed values}')]
#[Description('Verify (or repair) product stock and HPP against the stock ledger')]
class RebuildStock extends Command
{
    public function handle(): int
    {
        $mismatches = 0;

        Product::withTrashed()->orderBy('id')->each(function (Product $product) use (&$mismatches) {
            [$stock, $avg] = self::replay($product->id);

            $ok = $stock === $product->stock_on_hand && Decimal::equals($avg, (string) $product->avg_cost);
            if ($ok) {
                return;
            }

            $mismatches++;
            $this->warn(sprintf(
                '%s (%s): cache stok=%d hpp=%s, ledger stok=%d hpp=%s',
                $product->name,
                $product->sku,
                $product->stock_on_hand,
                $product->avg_cost,
                $stock,
                $avg,
            ));

            if ($this->option('fix')) {
                $product->forceFill(['stock_on_hand' => $stock, 'avg_cost' => $avg])->saveQuietly();
            }
        });

        $mismatches += $this->checkBatches();

        if ($mismatches === 0) {
            $this->info('Semua stok cocok dengan buku besar.');

            return self::SUCCESS;
        }

        $this->line($this->option('fix')
            ? "{$mismatches} produk diperbaiki dari buku besar."
            : "{$mismatches} produk tidak cocok. Jalankan dengan --fix untuk memperbaiki.");

        return $this->option('fix') ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Batches (DESIGN.md §19): each batch's cache equals the sum of its movements and never ran
     * negative; a product that tracks expiry has no stock outside batches (its movements without
     * a batch sum to 0, so its batches add up to its stock), and one that does not has nothing
     * left in batches. Only batch caches can be repaired; stock outside batches needs a person.
     */
    private function checkBatches(): int
    {
        $mismatches = 0;

        StockBatch::query()->with('product')->orderBy('id')->each(function (StockBatch $batch) use (&$mismatches) {
            [$qty, $wentNegative] = self::replayBatch($batch->id);

            if ($qty === $batch->qty_on_hand && ! $wentNegative) {
                return;
            }

            $mismatches++;
            $this->warn(sprintf(
                '%s, batch %s: cache sisa=%d, ledger sisa=%d%s',
                $batch->product->name,
                $batch->label(),
                $batch->qty_on_hand,
                $qty,
                $wentNegative ? ' (pernah negatif)' : '',
            ));

            if ($this->option('fix')) {
                $batch->forceFill(['qty_on_hand' => $qty])->saveQuietly();
            }
        });

        $outside = StockMovement::query()
            ->whereNull('stock_batch_id')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) AS qty')
            ->pluck('qty', 'product_id');
        $inBatches = StockMovement::query()
            ->whereNotNull('stock_batch_id')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty) AS qty')
            ->pluck('qty', 'product_id');

        Product::withTrashed()->orderBy('id')->each(function (Product $product) use (&$mismatches, $outside, $inBatches) {
            $loose = (int) ($outside[$product->id] ?? 0);
            $batched = (int) ($inBatches[$product->id] ?? 0);

            if ($product->tracks_expiry && $loose !== 0) {
                $mismatches++;
                $this->warn("{$product->name} ({$product->sku}): lacak kedaluwarsa, tetapi {$loose} stok tanpa batch.");
            } elseif (! $product->tracks_expiry && $batched !== 0) {
                $mismatches++;
                $this->warn("{$product->name} ({$product->sku}): tidak lacak kedaluwarsa, tetapi {$batched} stok masih di batch.");
            }
        });

        return $mismatches;
    }

    /**
     * A batch's quantity from its raw movements, and whether the running total ever went negative.
     *
     * @return array{int, bool}
     */
    public static function replayBatch(int $batchId): array
    {
        $qty = 0;
        $wentNegative = false;

        StockMovement::query()->where('stock_batch_id', $batchId)->orderBy('id')->each(function (StockMovement $m) use (&$qty, &$wentNegative) {
            $qty += $m->qty;
            $wentNegative = $wentNegative || $qty < 0;
        });

        return [$qty, $wentNegative];
    }

    /**
     * Recompute stock and moving-average HPP from raw movements only (qty, type, unit_cost),
     * deliberately ignoring the stored stock_after / avg_cost_after snapshots.
     *
     * @return array{int, string}
     */
    public static function replay(int $productId): array
    {
        $stock = 0;
        $avg = Decimal::round('0');

        StockMovement::query()
            ->where('product_id', $productId)
            ->orderBy('id')
            ->each(function (StockMovement $m) use (&$stock, &$avg) {
                if ($m->qty > 0 && $m->type->revaluesAverage()) {
                    $avg = StockLedger::reweigh($stock, $avg, $m->qty, (string) $m->unit_cost);
                }
                $stock += $m->qty;
            });

        return [$stock, $avg];
    }
}
