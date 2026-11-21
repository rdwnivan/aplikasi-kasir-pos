<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a product together with its sell units (pcs / pak / dus).
 *
 * Invariants:
 * - the base unit always has a product_units row with conversion 1, and only it does;
 * - each unit appears once per product; barcodes are unique across all products;
 * - the base unit cannot change once the product has stock history, because every
 *   ledger quantity is expressed in it.
 *
 * Stock and HPP are never written here: those belong to the StockLedger.
 */
final class SaveProduct
{
    /**
     * @param  array{sku: string, name: string, category_id: int|null, base_unit_id: int, min_stock: int, is_active: bool}  $attributes
     * @param  list<array{unit_id: int, conversion: int, price: int, barcode: string|null}>  $units
     */
    public function handle(array $attributes, array $units, ?Product $product = null): Product
    {
        $this->validateUnits($attributes['base_unit_id'], $units);

        if ($product !== null
            && $product->base_unit_id !== $attributes['base_unit_id']
            && $product->stockMovements()->exists()) {
            throw ValidationException::withMessages([
                'base_unit_id' => 'Satuan dasar tidak bisa diubah karena produk sudah punya riwayat stok.',
            ]);
        }

        return DB::transaction(function () use ($attributes, $units, $product) {
            $product ??= new Product;
            $product->fill($attributes)->save();

            $this->syncUnits($product, $units);

            return $product->load('units');
        });
    }

    /** @param list<array{unit_id: int, conversion: int, price: int, barcode: string|null}> $units */
    private function validateUnits(int $baseUnitId, array $units): void
    {
        $errors = [];
        $seenUnits = [];
        $seenBarcodes = [];
        $hasBase = false;

        foreach ($units as $i => $unit) {
            if (isset($seenUnits[$unit['unit_id']])) {
                $errors["units.$i.unit_id"] = 'Satuan ini sudah dipakai di baris lain.';
            }
            $seenUnits[$unit['unit_id']] = true;

            if ($unit['unit_id'] === $baseUnitId) {
                $hasBase = true;
                if ($unit['conversion'] !== 1) {
                    $errors["units.$i.conversion"] = 'Konversi satuan dasar harus 1.';
                }
            } elseif ($unit['conversion'] <= 1) {
                $errors["units.$i.conversion"] = 'Konversi satuan selain satuan dasar harus lebih dari 1.';
            }

            if ($unit['barcode'] !== null) {
                if (isset($seenBarcodes[$unit['barcode']])) {
                    $errors["units.$i.barcode"] = 'Barcode ini sudah dipakai di baris lain.';
                }
                $seenBarcodes[$unit['barcode']] = true;
            }
        }

        if (! $hasBase) {
            $errors['units'] = 'Satuan dasar wajib punya harga jual (baris dengan konversi 1).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Rows are matched by unit, not by id, so swapping which row holds which unit can never
     * trip the (product_id, unit_id) unique index halfway through.
     *
     * @param  list<array{unit_id: int, conversion: int, price: int, barcode: string|null}>  $units
     */
    private function syncUnits(Product $product, array $units): void
    {
        $existing = $product->units()->get()->keyBy('unit_id');
        $wanted = collect($units)->keyBy('unit_id');

        $product->units()->whereNotIn('unit_id', $wanted->keys()->all())->delete();

        // Free this product's barcodes first so moving a barcode between two of its own
        // units does not collide with the unique index mid-update.
        $product->units()->whereIn('unit_id', $wanted->keys()->all())->update(['barcode' => null]);

        foreach ($wanted as $unitId => $unit) {
            /** @var ProductUnit $row */
            $row = $existing->get($unitId) ?? new ProductUnit(['product_id' => $product->id, 'unit_id' => $unitId]);
            $row->fill([
                'conversion' => $unit['conversion'],
                'price' => $unit['price'],
                'barcode' => $unit['barcode'],
            ])->save();
        }
    }
}
