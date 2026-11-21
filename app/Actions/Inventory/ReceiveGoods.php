<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records goods arriving from a supplier (no PO). Each line may be in any of the product's
 * units (pcs / pak / dus); stock is booked in base units and the purchase price per base
 * unit re-weights the moving-average HPP.
 */
final class ReceiveGoods
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $items
     */
    public function handle(
        User $user,
        array $items,
        ?int $supplierId = null,
        ?CarbonInterface $receivedAt = null,
        ?string $note = null,
    ): GoodsReceipt {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus diisi.']);
        }

        $receivedAt ??= now();
        $units = $this->resolveUnits($items);

        return DB::transaction(function () use ($user, $items, $supplierId, $receivedAt, $note, $units) {
            $receipt = GoodsReceipt::create([
                'number' => $this->numbers->next(DocumentNumber::GOODS_RECEIPT, $receivedAt),
                'supplier_id' => $supplierId,
                'user_id' => $user->id,
                'received_at' => $receivedAt,
                'note' => $note,
            ]);

            $total = 0;

            foreach ($items as $i => $line) {
                $unit = $units[$i];
                $subtotal = $line['qty'] * $line['unit_price'];
                $total += $subtotal;

                $receipt->items()->create([
                    'product_id' => $line['product_id'],
                    'unit_id' => $line['unit_id'],
                    'qty' => $line['qty'],
                    'conversion' => $unit->conversion,
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $subtotal,
                ]);
            }

            // Book stock in product-id order, the same lock order checkout uses, so a
            // receipt and a sale touching the same products cannot deadlock.
            $order = array_keys($items);
            usort($order, fn (int $a, int $b) => [$items[$a]['product_id'], $a] <=> [$items[$b]['product_id'], $b]);

            foreach ($order as $i) {
                $unit = $units[$i];

                $this->ledger->receive(
                    $unit->product,
                    $items[$i]['qty'] * $unit->conversion,
                    Decimal::div((string) $items[$i]['unit_price'], (string) $unit->conversion),
                    StockMovementType::Purchase,
                    reference: $receipt,
                    user: $user,
                );
            }

            $receipt->update(['total' => $total]);

            return $receipt->load('items');
        });
    }

    /**
     * Validate every line up front so a bad line never leaves a half-posted receipt.
     *
     * @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $items
     * @return array<int, ProductUnit>
     */
    private function resolveUnits(array $items): array
    {
        $resolved = [];
        $errors = [];

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

            $resolved[$i] = $unit;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }
}
