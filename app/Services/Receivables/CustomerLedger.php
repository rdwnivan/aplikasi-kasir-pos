<?php

namespace App\Services\Receivables;

use App\Enums\LedgerEntryType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Sale;
use App\Models\User;
use App\Support\Rupiah;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of customer debt (kasbon, DESIGN.md §17). Every change appends an
 * immutable row to customer_ledger_entries and updates the customers.balance cache in the
 * same transaction, under a row lock on the customer, the way StockLedger guards stock.
 *
 * Every row belongs to one kasbon sale, so what is still owed on a sale is Σ amount for its
 * sale_id: payments and write-offs are split across sales, oldest first.
 *
 * Callers lock the customer first (lock()) inside their transaction, after the shift and
 * before any sale or product: shift → customer → sale → products.
 */
final class CustomerLedger
{
    /** Lock the customer row for the rest of the surrounding transaction. */
    public function lock(Customer $customer): Customer
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Lock a customer inside a transaction.');
        }

        /** @var Customer */
        return Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Kasbon given on a sale: checks the customer is active and the limit holds. */
    public function charge(Customer $locked, Sale $sale, int $amount, User $user): CustomerLedgerEntry
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Kasbon must be positive.');
        }
        self::ensureCanBorrow($locked, $amount);

        return $this->write($locked, $sale->id, LedgerEntryType::Sale, $amount, $sale, $user);
    }

    /**
     * Refuse kasbon the customer may not take: deactivated, no limit, or over it.
     *
     * @throws ValidationException on `customer_id`
     */
    public static function ensureCanBorrow(Customer $customer, int $amount): void
    {
        $error = match (true) {
            ! $customer->isActive() => "{$customer->name} sudah dinonaktifkan dan tidak bisa kasbon.",
            $customer->credit_limit === 0 => "{$customer->name} belum punya batas kasbon. Minta owner mengaturnya.",
            $customer->balance + $amount > $customer->credit_limit => sprintf(
                'Batas kasbon %s %s, sisa %s.',
                $customer->name,
                Rupiah::format($customer->credit_limit),
                Rupiah::format($customer->availableCredit()),
            ),
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['customer_id' => $error]);
        }
    }

    /**
     * Take an amount off the debt (payment or write-off), oldest kasbon first, or off one
     * sale only. The caller checks the amount fits; one row per sale it touches.
     *
     * @return list<CustomerLedgerEntry>
     */
    public function allocate(Customer $locked, LedgerEntryType $type, int $amount, Model $reference, User $user, ?Sale $only = null, ?string $note = null): array
    {
        if (! in_array($type, [LedgerEntryType::Payment, LedgerEntryType::WriteOff], true)) {
            throw new InvalidArgumentException("Entry type [{$type->value}] is not allocated.");
        }
        if ($amount <= 0 || $amount > $locked->balance) {
            throw new InvalidArgumentException('Allocated amount must be positive and at most the balance.');
        }

        $entries = [];
        $left = $amount;
        foreach ($this->openSales($locked) as $open) {
            if ($only !== null && $open['sale_id'] !== $only->id) {
                continue;
            }
            $take = min($left, $open['owed']);
            $entries[] = $this->write($locked, $open['sale_id'], $type, -$take, $reference, $user, $note);
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }

        if ($left !== 0) {
            throw new LogicException('The open kasbon does not add up to the balance; run receivables:rebuild.');
        }

        return $entries;
    }

    /**
     * A return from a kasbon sale: take up to $upTo off what is still owed on that sale.
     * Returns how much it took; the rest of the return is refunded in cash by the caller.
     */
    public function reverse(Customer $locked, Sale $sale, int $upTo, Model $return, User $user): int
    {
        $take = min($upTo, $this->owedOn($sale->id));

        if ($take > 0) {
            $this->write($locked, $sale->id, LedgerEntryType::SaleReturn, -$take, $return, $user);
        }

        return $take;
    }

    /** What is still owed on one kasbon sale. */
    public function owedOn(int $saleId): int
    {
        return (int) CustomerLedgerEntry::query()->where('sale_id', $saleId)->sum('amount');
    }

    /**
     * The customer's kasbon sales that are not paid off yet, oldest first.
     *
     * @return list<array{sale_id: int, number: string, completed_at: Carbon, total: int, credit: int, owed: int}>
     */
    public function openSales(Customer $customer): array
    {
        $owed = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->groupBy('sale_id')
            ->havingRaw('SUM(amount) > 0')
            ->selectRaw('sale_id, SUM(amount) AS owed')
            ->pluck('owed', 'sale_id');

        if ($owed->isEmpty()) {
            return [];
        }

        return array_values(Sale::query()
            ->whereIn('id', $owed->keys())
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get(['id', 'number', 'completed_at', 'total', 'credit_amount'])
            ->map(fn (Sale $s) => [
                'sale_id' => $s->id,
                'number' => (string) $s->number,
                'completed_at' => $s->completed_at ?? Carbon::now(),
                'total' => $s->total,
                'credit' => (int) $s->credit_amount,
                'owed' => (int) $owed[$s->id],
            ])
            ->all());
    }

    private function write(Customer $locked, int $saleId, LedgerEntryType $type, int $signed, Model $reference, User $user, ?string $note = null): CustomerLedgerEntry
    {
        $after = $locked->balance + $signed;

        if ($after < 0) {
            throw new LogicException('A customer balance cannot go below zero.');
        }

        $locked->forceFill(['balance' => $after])->save();

        return CustomerLedgerEntry::create([
            'customer_id' => $locked->id,
            'sale_id' => $saleId,
            'type' => $type,
            'amount' => $signed,
            'balance_after' => $after,
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'user_id' => $user->id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
