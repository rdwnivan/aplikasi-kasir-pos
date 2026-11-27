<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_disposal_id
 * @property int $stock_batch_id
 * @property int $product_id
 * @property int $qty base units
 * @property string $unit_cost HPP per base unit it left at (the average then)
 * @property int $value qty × unit_cost, rupiah
 */
#[Fillable(['stock_batch_id', 'product_id', 'qty', 'unit_cost', 'value'])]
class StockDisposalItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_cost' => 'decimal:4',
            'value' => 'integer',
        ];
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
