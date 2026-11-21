<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sellable unit of a product (pcs / pak / dus) with its conversion to the base unit.
 *
 * @property int $id
 * @property int $product_id
 * @property int $unit_id
 * @property int $conversion Base units contained in one of this unit.
 * @property string|null $barcode
 * @property int $price Selling price per this unit, in Rupiah.
 */
#[Fillable(['product_id', 'unit_id', 'conversion', 'barcode', 'price'])]
class ProductUnit extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'conversion' => 'integer',
            'price' => 'integer',
        ];
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
