<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Sales and gross profit per day or month.
 *
 * Gross profit is computed on revenue excluding PPN, because PPN is collected for the state
 * and is not the store's income:
 *   net sales    = (sales total − sales PPN) − (refunds − refund PPN)
 *   gross profit = net sales − (HPP of goods sold − HPP of goods returned)
 * Sales count by completion time, returns by the time they were processed.
 */
final class SalesSummary
{
    /**
     * @param  'day'|'month'  $group
     * @return array{rows: list<array{period: string, transactions: int, gross: int, cash: int, qris: int, refunds: int, tax: int, netSales: int, cogs: int, grossProfit: int, margin: float}>, totals: array{transactions: int, gross: int, cash: int, qris: int, refunds: int, tax: int, netSales: int, cogs: int, grossProfit: int, margin: float, averageTicket: int}}
     */
    public static function build(DateRange $range, string $group = 'day'): array
    {
        $saleBucket = Sql::bucket('completed_at', $group);
        $sales = DB::table('sales')
            ->where('status', SaleStatus::Completed->value)
            ->where('completed_at', '>=', $range->startsAt())
            ->where('completed_at', '<', $range->endsBefore())
            ->groupByRaw($saleBucket)
            ->selectRaw("{$saleBucket} AS bucket")
            ->selectRaw('COUNT(*) AS transactions')
            ->selectRaw('SUM(total) AS gross')
            ->selectRaw('SUM(tax_amount) AS tax')
            ->selectRaw('SUM(cogs_total) AS cogs')
            ->selectRaw('SUM('.Sql::cashPart().') AS cash')
            ->selectRaw('SUM('.Sql::qrisPart().') AS qris')
            ->get()
            ->keyBy('bucket');

        $returnBucket = Sql::bucket('created_at', $group);
        $returns = DB::table('sale_returns')
            ->where('created_at', '>=', $range->startsAt())
            ->where('created_at', '<', $range->endsBefore())
            ->groupByRaw($returnBucket)
            ->selectRaw("{$returnBucket} AS bucket")
            ->selectRaw('SUM(total) AS refunds')
            ->selectRaw('SUM(tax_amount) AS refund_tax')
            ->selectRaw('SUM(cogs_total) AS refund_cogs')
            ->get()
            ->keyBy('bucket');

        $rows = [];
        foreach (self::buckets($range, $group) as $bucket) {
            $s = $sales->get($bucket);
            $r = $returns->get($bucket);

            $row = self::row(
                $bucket,
                transactions: (int) ($s->transactions ?? 0),
                gross: Sql::rupiah($s->gross ?? 0),
                tax: Sql::rupiah($s->tax ?? 0),
                cogs: Sql::rupiah($s->cogs ?? 0),
                cash: Sql::rupiah($s->cash ?? 0),
                qris: Sql::rupiah($s->qris ?? 0),
                refunds: Sql::rupiah($r->refunds ?? 0),
                refundTax: Sql::rupiah($r->refund_tax ?? 0),
                refundCogs: Sql::rupiah($r->refund_cogs ?? 0),
            );
            $rows[] = $row;
        }

        $totals = ['transactions' => 0, 'gross' => 0, 'cash' => 0, 'qris' => 0, 'refunds' => 0, 'tax' => 0, 'netSales' => 0, 'cogs' => 0, 'grossProfit' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += $row[$key];
            }
        }
        $totals['margin'] = self::margin($totals['grossProfit'], $totals['netSales']);
        $totals['averageTicket'] = $totals['transactions'] > 0 ? intdiv($totals['gross'], $totals['transactions']) : 0;

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return array{period: string, transactions: int, gross: int, cash: int, qris: int, refunds: int, tax: int, netSales: int, cogs: int, grossProfit: int, margin: float} */
    private static function row(
        string $bucket,
        int $transactions,
        int $gross,
        int $tax,
        int $cogs,
        int $cash,
        int $qris,
        int $refunds,
        int $refundTax,
        int $refundCogs,
    ): array {
        $netSales = ($gross - $tax) - ($refunds - $refundTax);
        $netCogs = $cogs - $refundCogs;
        $grossProfit = $netSales - $netCogs;

        return [
            'period' => $bucket,
            'transactions' => $transactions,
            'gross' => $gross,
            'cash' => $cash,
            'qris' => $qris,
            'refunds' => $refunds,
            'tax' => $tax - $refundTax,
            'netSales' => $netSales,
            'cogs' => $netCogs,
            'grossProfit' => $grossProfit,
            'margin' => self::margin($grossProfit, $netSales),
        ];
    }

    private static function margin(int $profit, int $sales): float
    {
        return $sales === 0 ? 0.0 : round($profit / $sales * 100, 1);
    }

    /**
     * Every day (or month) in the range, so quiet days show as zero rather than vanish.
     *
     * @return list<string>
     */
    private static function buckets(DateRange $range, string $group): array
    {
        $buckets = [];
        $cursor = $group === 'month' ? $range->from->startOfMonth() : $range->from;
        $end = $range->to;

        while ($cursor->lessThanOrEqualTo($end)) {
            $buckets[] = $cursor->format($group === 'month' ? 'Y-m' : 'Y-m-d');
            $cursor = $group === 'month' ? $cursor->addMonth() : $cursor->addDay();
        }

        return $buckets;
    }
}
