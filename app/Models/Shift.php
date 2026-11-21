<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $opened_at
 * @property int $opening_cash
 * @property Carbon|null $closed_at
 * @property int|null $expected_cash
 * @property int|null $counted_cash
 * @property int|null $cash_difference
 * @property string|null $note
 */
#[Fillable(['user_id', 'opened_at', 'opening_cash', 'note'])]
class Shift extends Model
{
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'cash_difference' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Cash that should be in the drawer right now: float plus completed cash sales, minus
     * cash refunded for returns processed in this shift (whatever shift the sale was in).
     */
    public function expectedCash(): int
    {
        return $this->opening_cash + $this->cashSalesTotal() - $this->refundsTotal();
    }

    public function cashSalesTotal(): int
    {
        return (int) $this->sales()
            ->where('status', SaleStatus::Completed)
            ->where('payment_method', PaymentMethod::Cash)
            ->sum('total');
    }

    public function refundsTotal(): int
    {
        return (int) $this->returns()->sum('total');
    }

    /** @param Builder<Shift> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<SaleReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }
}
