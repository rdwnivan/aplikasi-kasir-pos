<?php

namespace App\Actions\Inventory;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockOpname;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Draft counts shared by CreateStockOpname and UpdateStockOpname. A product that tracks expiry
 * is counted per batch (DESIGN.md §19 P6): its line's counted_qty is the sum of its batches,
 * whatever total was sent.
 */
trait SavesOpnameCounts
{
    /**
     * @param  array<int, int>  $counts  product_id => counted qty in base units
     * @param  array<int, list<array<string, mixed>>>  $batches  product_id => [{stock_batch_id?, expires_on?, code?, counted_qty}]
     * @return array{0: array<int, int>, 1: array<int, list<array{stock_batch_id: int|null, expires_on: string|null, code: string|null, counted_qty: int}>>}
     */
    private function checkCounts(array $counts, array $batches): array
    {
        if ($counts === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus dihitung.']);
        }

        $errors = [];
        foreach ($counts as $productId => $qty) {
            if ($qty < 0) {
                $errors["items.$productId"] = 'Hasil hitung tidak boleh negatif.';
            }
        }

        $products = Product::query()->whereIn('id', array_keys($counts))->get()->keyBy('id');
        foreach (array_diff(array_keys($counts), $products->keys()->all()) as $missing) {
            $errors["items.$missing"] = 'Produk tidak ditemukan.';
        }

        $clean = [];
        foreach ($products as $product) {
            $lines = $batches[$product->id] ?? [];

            if (! $product->tracks_expiry) {
                if ($lines !== []) {
                    $errors["items.{$product->id}.batches"] = "{$product->name} tidak melacak tanggal kedaluwarsa.";
                }

                continue;
            }
            if ($lines === []) {
                $errors["items.{$product->id}.batches"] = "Hitung {$product->name} per tanggal kedaluwarsa.";

                continue;
            }

            $own = StockBatch::query()->where('product_id', $product->id)->pluck('id')->all();
            $seen = [];
            foreach ($lines as $line) {
                $batchId = isset($line['stock_batch_id']) ? (int) $line['stock_batch_id'] : null;
                $counted = (int) ($line['counted_qty'] ?? -1);
                $date = isset($line['expires_on']) && $line['expires_on'] !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $line['expires_on']) : null;
                $code = isset($line['code']) && trim((string) $line['code']) !== '' ? trim((string) $line['code']) : null;

                $error = match (true) {
                    $counted < 0 => 'Hasil hitung tidak boleh negatif.',
                    $batchId !== null && ! in_array($batchId, $own, true) => "Batch ini bukan milik {$product->name}.",
                    $batchId !== null && isset($seen[$batchId]) => 'Batch ini dihitung dua kali.',
                    $batchId === null && ! $date instanceof CarbonImmutable => 'Batch baru butuh tanggal kedaluwarsa.',
                    default => null,
                };
                if ($error !== null) {
                    $errors["items.{$product->id}.batches"] = $error;

                    break;
                }

                if ($batchId !== null) {
                    $seen[$batchId] = true;
                }
                $clean[$product->id][] = [
                    'stock_batch_id' => $batchId,
                    'expires_on' => $batchId === null && $date instanceof CarbonImmutable ? $date->toDateString() : null,
                    'code' => $batchId === null ? $code : null,
                    'counted_qty' => $counted,
                ];
            }

            $counts[$product->id] = array_sum(array_column($clean[$product->id] ?? [], 'counted_qty'));
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$counts, $clean];
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, list<array{stock_batch_id: int|null, expires_on: string|null, code: string|null, counted_qty: int}>>  $batches
     */
    private function writeCounts(StockOpname $opname, array $counts, array $batches): void
    {
        foreach ($counts as $productId => $qty) {
            $item = $opname->items()->create(['product_id' => $productId, 'counted_qty' => $qty]);

            foreach ($batches[$productId] ?? [] as $line) {
                $item->batches()->create($line);
            }
        }
    }
}
