<?php

namespace App\Console\Commands;

use App\Models\Product;
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
