<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $stock_opname_id
 * @property int $product_id
 * @property int|null $system_qty Stock on hand at posting time (null while draft).
 * @property int $counted_qty Physically counted, in base units.
 * @property int|null $difference counted − system, set at posting.
 */
#[Fillable(['product_id', 'counted_qty'])]
class StockOpnameItem extends Model
{
    protected function casts(): array
    {
        return [
            'system_qty' => 'integer',
            'counted_qty' => 'integer',
            'difference' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
