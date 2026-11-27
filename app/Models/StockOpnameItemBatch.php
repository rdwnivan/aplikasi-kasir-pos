<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One counted batch on a stock opname line, for a product that tracks expiry (DESIGN.md §19).
 * A batch found during the count has no stock_batch_id until posting creates it.
 *
 * @property int $id
 * @property int $stock_opname_item_id
 * @property int|null $stock_batch_id
 * @property Carbon|null $expires_on
 * @property string|null $code
 * @property int $counted_qty
 * @property int|null $system_qty set at posting
 * @property int|null $difference set at posting
 */
#[Fillable(['stock_batch_id', 'expires_on', 'code', 'counted_qty'])]
class StockOpnameItemBatch extends Model
{
    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'counted_qty' => 'integer',
            'system_qty' => 'integer',
            'difference' => 'integer',
        ];
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** "12 Des 2026 · L42", "Tanpa tanggal", for the batch as counted. */
    public function label(): string
    {
        return $this->batch?->label() ?? (new StockBatch(['expires_on' => $this->expires_on, 'code' => $this->code]))->label();
    }
}
