<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sums the customer credit ledger and compares it with the customers.balance cache, and
 * checks that no kasbon sale has a negative amount owed (DESIGN.md §17). `--fix` rewrites
 * the cache from the ledger; a negative sale is reported but never "fixed", because only a
 * new ledger entry may correct it.
 */
#[Signature('receivables:rebuild {--fix : Overwrite the customer balance cache with the ledger sum}')]
#[Description('Verify (or repair) customer kasbon balances against the customer ledger')]
class RebuildReceivables extends Command
{
    public function handle(): int
    {
        $sums = CustomerLedgerEntry::query()->groupBy('customer_id')->selectRaw('customer_id, SUM(amount) AS total')->pluck('total', 'customer_id');
        $mismatches = 0;

        Customer::query()->orderBy('id')->each(function (Customer $customer) use ($sums, &$mismatches) {
            $ledger = (int) ($sums[$customer->id] ?? 0);
            if ($ledger === $customer->balance) {
                return;
            }

            $mismatches++;
            $this->warn("{$customer->name} (#{$customer->id}): cache utang={$customer->balance}, ledger={$ledger}");

            if ($this->option('fix')) {
                $customer->forceFill(['balance' => $ledger])->saveQuietly();
            }
        });

        $negative = DB::table('customer_ledger_entries')->groupBy('sale_id')->havingRaw('SUM(amount) < 0')->selectRaw('sale_id, SUM(amount) AS owed')->get();
        foreach ($negative as $row) {
            $this->error("Kasbon sale #{$row->sale_id} punya sisa utang negatif ({$row->owed}).");
        }

        if ($mismatches === 0 && $negative->isEmpty()) {
            $this->info('Semua utang pelanggan cocok dengan ledger.');

            return self::SUCCESS;
        }

        if ($mismatches > 0) {
            $this->line($this->option('fix')
                ? "{$mismatches} pelanggan diperbaiki dari ledger."
                : "{$mismatches} pelanggan tidak cocok. Jalankan dengan --fix untuk memperbaiki.");
        }

        return $this->option('fix') && $negative->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
