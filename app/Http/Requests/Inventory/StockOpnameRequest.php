<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StockOpnameRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:5000'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.counted_qty' => ['required', 'integer', 'min:0', 'max:100000000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'items' => 'hasil hitung',
            'items.*.product_id' => 'produk',
            'items.*.counted_qty' => 'hasil hitung',
            'note' => 'catatan',
        ];
    }

    /** @return array<int, int> product_id => counted qty */
    public function counts(): array
    {
        $counts = [];
        foreach ($this->validated('items') as $item) {
            $counts[(int) $item['product_id']] = (int) $item['counted_qty'];
        }

        return $counts;
    }
}
