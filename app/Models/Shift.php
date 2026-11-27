<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Reports\Sql;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * @property int|null $closed_by null on shifts closed before DESIGN.md §13: read as the cashier
 * @property string|null $close_reason why someone other than the cashier closed it
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
            'closed_by' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /** Closed by someone other than its own cashier (an owner, DESIGN.md §13). */
    public function closedByOther(): bool
    {
        return $this->closed_by !== null && $this->closed_by !== $this->user_id;
    }

    /**
     * Cash that should be in the drawer right now: float plus completed cash sales and kasbon
     * paid off here, minus cash refunded for returns processed in this shift (whatever shift
     * the sale was in).
     */
    public function expectedCash(): int
    {
        return $this->opening_cash + $this->cashSalesTotal() + $this->cashPaymentsTotal() - $this->refundsTotal();
    }

    /** Cash taken in: whole cash sales plus the cash part of mixed and kasbon sales. */
    public function cashSalesTotal(): int
    {
        return Sql::rupiah($this->sales()
            ->where('status', SaleStatus::Completed)
            ->sum(DB::raw(Sql::cashPart())));
    }

    /**
     * Completed sales that put cash in the drawer: the same cash part as cashSalesTotal(), so
     * kasbon without a down payment (and plain QRIS) is not counted.
     */
    public function cashSalesCount(): int
    {
        return $this->sales()
            ->where('status', SaleStatus::Completed)
            ->whereRaw('('.Sql::cashPart().') > 0')
            ->count();
    }

    /** Kasbon given in this shift (the part left owing). */
    public function creditSalesTotal(): int
    {
        return Sql::rupiah($this->sales()
            ->where('status', SaleStatus::Completed)
            ->sum(DB::raw(Sql::creditPart())));
    }

    /** Kasbon paid off in cash into this drawer (DESIGN.md §17). */
    public function cashPaymentsTotal(): int
    {
        return (int) $this->receivedPayments(CustomerPayment::CASH)->sum('amount');
    }

    /** Kasbon paid off by QRIS on this shift: shown, never in the drawer (DESIGN.md §18). */
    public function qrisPaymentsTotal(): int
    {
        return (int) $this->receivedPayments(CustomerPayment::QRIS)->sum('amount');
    }

    /** @return HasMany<CustomerPayment, $this> Payments received here (not waiting or cancelled QRIS). */
    public function receivedPayments(string $method): HasMany
    {
        return $this->customerPayments()->received()->where('method', $method);
    }

    /** Cash paid out for returns; the part of a return that came off a kasbon debt is not cash. */
    public function refundsTotal(): int
    {
        return Sql::rupiah($this->returns()->sum(DB::raw(Sql::cashRefund())));
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

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
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

    /** @return HasMany<CustomerPayment, $this> */
    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}
