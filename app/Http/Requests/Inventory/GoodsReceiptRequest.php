<?php

namespace App\Http\Requests\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GoodsReceiptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            ...self::batchRules(),
        ];
    }

    /**
     * Expiry dates per line for goods that track them (DESIGN.md §19), counted in the line's
     * unit. Whether a product needs them is checked by ReceiveGoods.
     *
     * @return array<string, mixed>
     */
    public static function batchRules(): array
    {
        return [
            'items.*.batches' => ['nullable', 'array', 'max:20'],
            'items.*.batches.*.expires_on' => ['required', 'date_format:Y-m-d'],
            'items.*.batches.*.qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'items.*.batches.*.code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{expires_on: string, qty: int, code: string|null}>
     */
    public static function batchesOf(array $item): array
    {
        return array_values(array_map(fn (array $b) => [
            'expires_on' => (string) $b['expires_on'],
            'qty' => (int) $b['qty'],
            'code' => isset($b['code']) && trim((string) $b['code']) !== '' ? trim((string) $b['code']) : null,
        ], $item['batches'] ?? []));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'received_at' => 'tanggal terima',
            'note' => 'catatan',
            'items' => 'barang',
            'items.*.product_id' => 'produk',
            'items.*.unit_id' => 'satuan',
            'items.*.qty' => 'jumlah',
            'items.*.unit_price' => 'harga beli',
        ];
    }

    /** @return list<array{product_id: int, unit_id: int, qty: int, unit_price: int, batches: list<array{expires_on: string, qty: int, code: string|null}>}> */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'unit_id' => (int) $item['unit_id'],
            'qty' => (int) $item['qty'],
            'unit_price' => (int) $item['unit_price'],
            'batches' => self::batchesOf($item),
        ], $this->validated('items')));
    }

    public function receivedAt(): CarbonImmutable
    {
        // Keep the time of day so movements still sort naturally within the date.
        return CarbonImmutable::parse($this->validated('received_at').' '.now()->format('H:i:s'));
    }
}
