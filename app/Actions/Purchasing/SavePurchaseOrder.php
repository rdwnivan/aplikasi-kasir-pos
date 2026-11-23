<?php

namespace App\Actions\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft purchase order, or rewrites one that is still a draft. Lines are in any of
 * the product's units at the price agreed with the supplier; nothing here touches stock.
 */
final class SavePurchaseOrder
{
    /**
     * @param  array{supplier_id: int, expected_at: string|null, note: string|null}  $attributes
     * @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $items
     */
    public function handle(User $user, array $attributes, array $items, ?PurchaseOrder $order = null): PurchaseOrder
    {
        $this->validateLines($items);

        return DB::transaction(function () use ($user, $attributes, $items, $order) {
            if ($order === null) {
                $order = PurchaseOrder::create([
                    'supplier_id' => $attributes['supplier_id'],
                    'user_id' => $user->id,
                    'status' => PurchaseOrderStatus::Draft,
                    'expected_at' => $attributes['expected_at'],
                    'note' => $attributes['note'],
                ]);
            } else {
                /** @var PurchaseOrder $order */
                $order = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

                if ($order->status !== PurchaseOrderStatus::Draft) {
                    throw ValidationException::withMessages(['order' => 'Hanya draft yang bisa diubah.']);
                }

                $order->fill([
                    'supplier_id' => $attributes['supplier_id'],
                    'expected_at' => $attributes['expected_at'],
                    'note' => $attributes['note'],
                ]);
                $order->items()->delete();
            }

            $total = 0;
            foreach ($items as $line) {
                $subtotal = $line['qty'] * $line['unit_price'];
                $total += $subtotal;
                $order->items()->create([...$line, 'subtotal' => $subtotal]);
            }

            $order->total = $total;
            $order->save();

            return $order->load('items');
        });
    }

    /** @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $items */
    private function validateLines(array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus diisi.']);
        }

        $errors = [];
        $seen = [];

        foreach ($items as $i => $line) {
            if ($line['qty'] <= 0) {
                $errors["items.$i.qty"] = 'Jumlah harus lebih dari 0.';
            }
            if ($line['unit_price'] < 0) {
                $errors["items.$i.unit_price"] = 'Harga beli tidak boleh negatif.';
            }

            $unit = ProductUnit::query()
                ->with('product')
                ->where('product_id', $line['product_id'])
                ->where('unit_id', $line['unit_id'])
                ->first();

            if ($unit === null || ! $unit->product instanceof Product || ! $unit->product->is_active) {
                $errors["items.$i.unit_id"] = 'Satuan tidak terdaftar untuk produk ini.';

                continue;
            }

            $key = $line['product_id'].'-'.$line['unit_id'];
            if (isset($seen[$key])) {
                $errors["items.$i.unit_id"] = 'Produk dan satuan ini sudah ada di baris lain.';
            }
            $seen[$key] = true;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
