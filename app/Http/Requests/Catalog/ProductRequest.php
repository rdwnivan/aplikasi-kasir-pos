<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sku' => is_string($this->input('sku')) ? mb_strtoupper(trim($this->input('sku'))) : $this->input('sku'),
            'units' => is_array($this->input('units')) ? array_map(fn (mixed $unit) => is_array($unit) ? [
                ...$unit,
                'barcode' => isset($unit['barcode']) && is_scalar($unit['barcode']) && trim((string) $unit['barcode']) !== ''
                    ? trim((string) $unit['barcode'])
                    : null,
            ] : $unit, $this->input('units')) : $this->input('units'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');
        $productId = $product instanceof Product ? $product->id : null;

        return [
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($productId)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'base_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
            'units' => ['required', 'array', 'min:1', 'max:10'],
            'units.*.unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'units.*.conversion' => ['required', 'integer', 'min:1', 'max:100000'],
            'units.*.price' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'units.*.barcode' => [
                'nullable', 'string', 'max:64',
                Rule::unique('product_units', 'barcode')->where(fn ($q) => $productId === null ? $q : $q->where('product_id', '!=', $productId)),
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'name' => 'nama produk',
            'category_id' => 'kategori',
            'base_unit_id' => 'satuan dasar',
            'min_stock' => 'stok minimum',
            'units' => 'satuan',
            'units.*.unit_id' => 'satuan',
            'units.*.conversion' => 'konversi',
            'units.*.price' => 'harga jual',
            'units.*.barcode' => 'barcode',
        ];
    }

    /** @return array{sku: string, name: string, category_id: int|null, base_unit_id: int, min_stock: int, is_active: bool} */
    public function productAttributes(): array
    {
        return [
            'sku' => (string) $this->validated('sku'),
            'name' => trim((string) $this->validated('name')),
            'category_id' => $this->validated('category_id') !== null ? (int) $this->validated('category_id') : null,
            'base_unit_id' => (int) $this->validated('base_unit_id'),
            'min_stock' => (int) $this->validated('min_stock'),
            'is_active' => $this->boolean('is_active', true),
        ];
    }

    /** @return list<array{unit_id: int, conversion: int, price: int, barcode: string|null}> */
    public function units(): array
    {
        return array_values(array_map(fn (array $unit) => [
            'unit_id' => (int) $unit['unit_id'],
            'conversion' => (int) $unit['conversion'],
            'price' => (int) $unit['price'],
            'barcode' => $unit['barcode'] ?? null,
        ], $this->validated('units')));
    }
}
