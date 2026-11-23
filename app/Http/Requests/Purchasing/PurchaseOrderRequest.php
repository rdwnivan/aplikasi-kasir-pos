<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseOrderRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')],
            'expected_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->toDateString()],
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
            'expected_at' => 'perkiraan tiba',
            'note' => 'catatan',
            'items' => 'barang',
            'items.*.product_id' => 'produk',
            'items.*.unit_id' => 'satuan',
            'items.*.qty' => 'jumlah',
            'items.*.unit_price' => 'harga beli',
        ];
    }

    /** @return array{supplier_id: int, expected_at: string|null, note: string|null} */
    public function attributesForOrder(): array
    {
        return [
            'supplier_id' => (int) $this->validated('supplier_id'),
            'expected_at' => $this->validated('expected_at'),
            'note' => $this->validated('note'),
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
}
