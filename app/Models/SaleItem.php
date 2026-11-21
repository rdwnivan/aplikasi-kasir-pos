<?php

namespace App\Models;

use App\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_id
 * @property int $product_id
 * @property int|null $product_unit_id
 * @property string $product_name
 * @property string $unit_name
 * @property int $qty
 * @property int $conversion
 * @property int $unit_price
 * @property DiscountType|null $discount_type
 * @property int|null $discount_value
 * @property int $discount_amount
 * @property int $line_total
 * @property string|null $unit_cost
 * @property string|null $cogs
 */
class SaleItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_type' => DiscountType::class,
            'unit_cost' => 'decimal:4',
            'cogs' => 'decimal:4',
            ...array_fill_keys([
                'qty', 'conversion', 'unit_price', 'discount_value', 'discount_amount', 'line_total',
            ], 'integer'),
        ];
    }

    public function baseQty(): int
    {
        return $this->qty * $this->conversion;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
