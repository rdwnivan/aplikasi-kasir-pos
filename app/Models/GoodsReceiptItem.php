<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $goods_receipt_id
 * @property int $product_id
 * @property int $unit_id
 * @property int $qty In the chosen unit.
 * @property int $conversion Base units per chosen unit, snapshotted at receipt time.
 * @property int $unit_price Purchase price per chosen unit, Rupiah.
 * @property int $subtotal
 */
#[Fillable(['product_id', 'unit_id', 'qty', 'conversion', 'unit_price', 'subtotal'])]
class GoodsReceiptItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'conversion' => 'integer',
            'unit_price' => 'integer',
            'subtotal' => 'integer',
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

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
