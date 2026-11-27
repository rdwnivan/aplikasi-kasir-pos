<?php

namespace App\Reports;

use App\Models\Customer;
use App\Support\DateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Kasbon owed per customer as of now, aged by how long ago each still-open kasbon was given
 * (calendar days in Asia/Jakarta): 0–30, 31–60, 61–90 and more than 90 days (DESIGN.md §17).
 * What is open on a sale comes straight from the ledger (Σ amount per sale_id).
 */
final class Receivables
{
    /**
     * How old a kasbon is in calendar days in Asia/Jakarta: given today = 0, yesterday
     * evening = 1. The one age rule for the report, the customer list and the till.
     */
    public static function ageInDays(CarbonImmutable $at, ?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(DateRange::TIMEZONE)->startOfDay();

        return (int) $at->setTimezone(DateRange::TIMEZONE)->startOfDay()->diffInDays($today);
    }

    /**
     * @return array{asOf: string, rows: list<array{id: int, customer: string, phone: string|null, balance: int, creditLimit: int, age0to30: int, age31to60: int, age61to90: int, age90plus: int, openSales: int, oldestAt: string|null, lastPaymentAt: string|null}>, totals: array{customers: int, balance: int, age0to30: int, age31to60: int, age61to90: int, age90plus: int, overdue: int}}
     */
    public static function build(): array
    {
        $now = CarbonImmutable::now(DateRange::TIMEZONE);
        $today = $now->startOfDay();

        $open = DB::table('customer_ledger_entries')
            ->join('sales', 'sales.id', '=', 'customer_ledger_entries.sale_id')
            ->groupBy('customer_ledger_entries.sale_id', 'customer_ledger_entries.customer_id', 'sales.completed_at')
            ->havingRaw('SUM(customer_ledger_entries.amount) > 0')
            ->selectRaw('customer_ledger_entries.customer_id AS customer_id, sales.completed_at AS completed_at, SUM(customer_ledger_entries.amount) AS owed')
            ->get();

        $lastPayments = DB::table('customer_payments')->groupBy('customer_id')->selectRaw('customer_id, MAX(created_at) AS at')->pluck('at', 'customer_id');
        $customers = Customer::query()->whereIn('id', $open->pluck('customer_id')->unique())->get()->keyBy('id');

        $rows = [];
        foreach ($open as $line) {
            $customer = $customers[$line->customer_id];
            $at = CarbonImmutable::parse($line->completed_at)->setTimezone(DateRange::TIMEZONE);
            $days = self::ageInDays($at, $today);
            $bucket = match (true) {
                $days <= 30 => 'age0to30',
                $days <= 60 => 'age31to60',
                $days <= 90 => 'age61to90',
                default => 'age90plus',
            };
            $owed = Sql::rupiah($line->owed);

            $row = $rows[$customer->id] ??= [
                'id' => $customer->id,
                'customer' => $customer->name,
                'phone' => $customer->phone,
                'balance' => 0,
                'creditLimit' => $customer->credit_limit,
                'age0to30' => 0, 'age31to60' => 0, 'age61to90' => 0, 'age90plus' => 0,
                'openSales' => 0,
                'oldestAt' => null,
                'lastPaymentAt' => isset($lastPayments[$customer->id])
                    ? CarbonImmutable::parse($lastPayments[$customer->id])->setTimezone(DateRange::TIMEZONE)->toIso8601String()
                    : null,
            ];
            $row['balance'] += $owed;
            $row[$bucket] += $owed;
            $row['openSales']++;
            if ($row['oldestAt'] === null || $at->lessThan(CarbonImmutable::parse($row['oldestAt']))) {
                $row['oldestAt'] = $at->toIso8601String();
            }
            $rows[$customer->id] = $row;
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => [$b['balance'], $a['customer']] <=> [$a['balance'], $b['customer']]);

        $totals = ['customers' => count($rows), 'balance' => 0, 'age0to30' => 0, 'age31to60' => 0, 'age61to90' => 0, 'age90plus' => 0];
        foreach ($rows as $row) {
            foreach (['balance', 'age0to30', 'age31to60', 'age61to90', 'age90plus'] as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $totals['overdue'] = $totals['balance'] - $totals['age0to30'];

        return ['asOf' => $now->toIso8601String(), 'rows' => $rows, 'totals' => $totals];
    }
}
