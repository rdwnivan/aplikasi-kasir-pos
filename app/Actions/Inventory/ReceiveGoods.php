<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records goods arriving from a supplier, directly or against a purchase order (then through
 * ReceivePurchaseOrder, which checks the lines against what is still outstanding). Each line may be in any of the product's
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
     * @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int, purchase_order_item_id?: int, batches?: list<array{expires_on: string, qty: int, code?: string|null}>, line?: int}>  $items
     */
    public function handle(
        User $user,
        array $items,
        ?int $supplierId = null,
        ?CarbonInterface $receivedAt = null,
        ?string $note = null,
        ?PurchaseOrder $purchaseOrder = null,
    ): GoodsReceipt {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu barang harus diisi.']);
        }

        $receivedAt ??= now();
        $units = $this->resolveUnits($items, $receivedAt);

        return DB::transaction(function () use ($user, $items, $supplierId, $receivedAt, $note, $units, $purchaseOrder) {
            $receipt = GoodsReceipt::create([
                'number' => $this->numbers->next(DocumentNumber::GOODS_RECEIPT, $receivedAt),
                'supplier_id' => $supplierId,
                'purchase_order_id' => $purchaseOrder?->id,
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
                    'purchase_order_item_id' => $line['purchase_order_item_id'] ?? null,
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
                    batches: $unit->product->tracks_expiry
                        ? array_map(fn (array $b) => [
                            'expires_on' => $b['expires_on'],
                            'code' => $b['code'] ?? null,
                            'qty' => $b['qty'] * $unit->conversion,
                        ], $items[$i]['batches'] ?? [])
                        : null,
                );
            }

            $receipt->update(['total' => $total]);

            return $receipt->load('items');
        });
    }

    /**
     * Validate every line up front so a bad line never leaves a half-posted receipt.
     *
     * @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int, purchase_order_item_id?: int, batches?: list<array{expires_on: string, qty: int, code?: string|null}>, line?: int}>  $items
     * @return array<int, ProductUnit>
     */
    private function resolveUnits(array $items, CarbonInterface $receivedAt): array
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

            $batchError = self::batchError($unit->product, $line, $receivedAt);
            if ($batchError !== null) {
                $errors['items.'.($line['line'] ?? $i).'.batches'] = $batchError;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * A product that tracks expiry needs its line split over expiry dates (counted in the
     * line's unit) adding up to the line; one that does not takes no dates (DESIGN.md §19 P4).
     *
     * @param  array<string, mixed>  $line
     */
    private static function batchError(Product $product, array $line, CarbonInterface $receivedAt): ?string
    {
        $batches = $line['batches'] ?? [];

        if (! $product->tracks_expiry) {
            return $batches === [] ? null : "{$product->name} tidak melacak tanggal kedaluwarsa.";
        }
        if ($batches === []) {
            return "Isi tanggal kedaluwarsa untuk {$product->name}.";
        }

        $sum = 0;
        foreach ($batches as $batch) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($batch['expires_on'] ?? ''));
            if (! $date instanceof CarbonImmutable || (int) ($batch['qty'] ?? 0) < 1) {
                return 'Setiap tanggal kedaluwarsa butuh tanggal dan jumlah lebih dari 0.';
            }
            if ($date->toDateString() < $receivedAt->toDateString()) {
                return 'Tanggal kedaluwarsa '.$date->settings(['locale' => 'id'])->translatedFormat('j M Y').' sudah lewat.';
            }
            $sum += (int) $batch['qty'];
        }

        return $sum === $line['qty'] ? null : "Jumlah per tanggal ({$sum}) harus sama dengan jumlah barang ({$line['qty']}).";
    }
}
