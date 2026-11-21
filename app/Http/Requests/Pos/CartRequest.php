<?php

namespace App\Http\Requests\Pos;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared shape for anything that submits a cart (checkout, hold). */
abstract class CartRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_unit_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:99999'],
            'items.*.discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'items.*.discount_value' => ['nullable', 'integer', 'min:0'],
            'discount_type' => ['nullable', Rule::enum(DiscountType::class)],
            'discount_value' => ['nullable', 'integer', 'min:0'],
            'held_sale_id' => ['nullable', 'integer'],
        ];
    }

    /** @return list<array{product_unit_id: int, qty: int, discount_type: string|null, discount_value: int}> */
    public function cartItems(): array
    {
        return array_values(array_map(fn (array $item) => [
            'product_unit_id' => (int) $item['product_unit_id'],
            'qty' => (int) $item['qty'],
            'discount_type' => $item['discount_type'] ?? null,
            'discount_value' => (int) ($item['discount_value'] ?? 0),
        ], $this->validated('items')));
    }

    public function cartDiscountType(): ?DiscountType
    {
        return $this->enum('discount_type', DiscountType::class);
    }

    public function cartDiscountValue(): int
    {
        return (int) $this->validated('discount_value', 0);
    }
}
