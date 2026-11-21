<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $sale_return_id
 * @property int $sale_item_id
 * @property int $product_id
 * @property int $qty In the unit it was sold in.
 * @property int $conversion
 * @property int $amount Refund for this line, Rupiah.
 * @property string $unit_cost HPP per base unit of the original sale line.
 * @property string $cogs
 */
class SaleReturnItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'conversion' => 'integer',
            'amount' => 'integer',
            'unit_cost' => 'decimal:4',
            'cogs' => 'decimal:4',
        ];
    }

    public function baseQty(): int
    {
        return $this->qty * $this->conversion;
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
