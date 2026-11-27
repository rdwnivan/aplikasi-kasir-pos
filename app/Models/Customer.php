<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A regular customer who may buy on credit (kasbon, DESIGN.md §17). Never deleted, since
 * sales and ledger rows point at it; deactivated instead.
 *
 * @property int $id
 * @property string $name
 * @property string|null $phone digits only
 * @property string|null $address
 * @property string|null $note
 * @property int $credit_limit Rupiah; 0 = no kasbon
 * @property int $balance Cache of the ledger; written only by App\Services\Receivables\CustomerLedger.
 * @property Carbon|null $deactivated_at
 * @property int $created_by
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $guarded = ['id', 'balance'];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'integer',
            'balance' => 'integer',
            'deactivated_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** How much more kasbon fits under the limit. */
    public function availableCredit(): int
    {
        return max(0, $this->credit_limit - $this->balance);
    }

    /** Keep only the digits of a phone number, so "0812-3456" and "08123456" match. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? null : $digits;
    }

    /** @param Builder<Customer> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('deactivated_at');
    }

    /** @return HasMany<CustomerLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }

    /** @return HasMany<CustomerPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
