<?php

namespace App\Http\Requests\Purchasing;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseOrderReceiptRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'received_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'received_at' => 'tanggal terima',
            'note' => 'catatan',
            'items' => 'barang',
            'items.*.purchase_order_item_id' => 'barang',
            'items.*.qty' => 'jumlah',
            'items.*.unit_price' => 'harga beli',
        ];
    }

    /** @return list<array{purchase_order_item_id: int, qty: int, unit_price: int}> */
    public function items(): array
    {
        return array_values(array_map(fn (array $item) => [
            'purchase_order_item_id' => (int) $item['purchase_order_item_id'],
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
