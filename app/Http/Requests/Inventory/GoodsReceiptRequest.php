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
        ];
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

    /** @return list<array{product_id: int, unit_id: int, qty: int, unit_price: int}> */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'unit_id' => (int) $item['unit_id'],
            'qty' => (int) $item['qty'],
            'unit_price' => (int) $item['unit_price'],
        ], $this->validated('items')));
    }

    public function receivedAt(): CarbonImmutable
    {
        // Keep the time of day so movements still sort naturally within the date.
        return CarbonImmutable::parse($this->validated('received_at').' '.now()->format('H:i:s'));
    }
}
