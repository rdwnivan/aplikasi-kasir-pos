<?php

namespace App\Reports;

use App\Enums\CustomerPaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Shift;
use App\Support\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * Per shift and per cashier: what was sold, how it was paid, what was refunded, and how the
 * counted drawer compared with what the system expected. Shifts are picked by opening time.
 */
final class ShiftRecap
{
    /** @return array{shifts: list<array<string, mixed>>, cashiers: list<array<string, mixed>>} */
    public static function build(DateRange $range): array
    {
        $shifts = Shift::query()
            ->with(['user', 'closer'])
            ->where('opened_at', '>=', $range->startsAt())
            ->where('opened_at', '<', $range->endsBefore())
            ->orderByDesc('opened_at')
            ->get();

        $ids = $shifts->pluck('id')->all();

        $sales = DB::table('sales')
            ->whereIn('shift_id', $ids)
            ->where('status', SaleStatus::Completed->value)
            ->groupBy('shift_id')
            ->selectRaw('shift_id, COUNT(*) AS transactions')
            ->selectRaw('SUM('.Sql::cashPart().') AS cash')
            ->selectRaw('SUM('.Sql::qrisPart().') AS qris')
            ->selectRaw('SUM('.Sql::creditPart().') AS credit')
            ->get()
            ->keyBy('shift_id');

        // Cash paid out; the part of a kasbon return that came off the debt is not cash.
        $refunds = DB::table('sale_returns')
            ->whereIn('shift_id', $ids)
            ->groupBy('shift_id')
            ->selectRaw('shift_id, COUNT(*) AS returns, SUM('.Sql::cashRefund().') AS refunds')
            ->get()
            ->keyBy('shift_id');

        // Pelunasan received: cash goes in the drawer, QRIS does not (DESIGN.md §18).
        $payments = DB::table('customer_payments')
            ->whereIn('shift_id', $ids)
            ->where('status', CustomerPaymentStatus::Completed->value)
            ->groupBy('shift_id')
            ->selectRaw('shift_id')
            ->selectRaw("SUM(CASE WHEN method = 'qris' THEN 0 ELSE amount END) AS cash")
            ->selectRaw("SUM(CASE WHEN method = 'qris' THEN amount ELSE 0 END) AS qris")
            ->get()
            ->keyBy('shift_id');

        $rows = [];
        $cashiers = [];
        foreach ($shifts as $shift) {
            $s = $sales->get($shift->id);
            $r = $refunds->get($shift->id);
            $cash = Sql::rupiah($s->cash ?? 0);
            $refund = Sql::rupiah($r->refunds ?? 0);
            $p = $payments->get($shift->id);
            $paid = Sql::rupiah($p->cash ?? 0);

            $row = [
                'id' => $shift->id,
                'cashier' => $shift->user->name,
                'openedAt' => $shift->opened_at->toIso8601String(),
                'closedAt' => $shift->closed_at?->toIso8601String(),
                'transactions' => (int) ($s->transactions ?? 0),
                'openingCash' => $shift->opening_cash,
                'cashSales' => $cash,
                'qrisSales' => Sql::rupiah($s->qris ?? 0),
                'creditSales' => Sql::rupiah($s->credit ?? 0),
                'payments' => $paid, // cash pelunasan, in the drawer
                'qrisPayments' => Sql::rupiah($p->qris ?? 0),
                'refunds' => $refund,
                'expectedCash' => $shift->expected_cash ?? $shift->opening_cash + $cash + $paid - $refund,
                'countedCash' => $shift->counted_cash,
                'difference' => $shift->cash_difference,
                // Only when someone other than the cashier closed it (DESIGN.md §13).
                'closedBy' => $shift->closedByOther() ? $shift->closer?->name : null,
                'closeReason' => $shift->closedByOther() ? $shift->close_reason : null,
            ];
            $rows[] = $row;

            $name = $row['cashier'];
            $cashiers[$name] ??= ['cashier' => $name, 'shifts' => 0, 'transactions' => 0, 'cashSales' => 0, 'qrisSales' => 0, 'creditSales' => 0, 'payments' => 0, 'qrisPayments' => 0, 'refunds' => 0, 'difference' => null];
            $cashiers[$name]['shifts']++;
            $cashiers[$name]['transactions'] += $row['transactions'];
            $cashiers[$name]['cashSales'] += $row['cashSales'];
            $cashiers[$name]['qrisSales'] += $row['qrisSales'];
            $cashiers[$name]['creditSales'] += $row['creditSales'];
            $cashiers[$name]['payments'] += $row['payments'];
            $cashiers[$name]['qrisPayments'] += $row['qrisPayments'];
            $cashiers[$name]['refunds'] += $row['refunds'];
            if ($row['difference'] !== null) { // only closed shifts have one
                $cashiers[$name]['difference'] = ($cashiers[$name]['difference'] ?? 0) + $row['difference'];
            }
        }

        $cashiers = array_values($cashiers);
        usort($cashiers, fn (array $a, array $b) => ($b['cashSales'] + $b['qrisSales'] + $b['creditSales']) <=> ($a['cashSales'] + $a['qrisSales'] + $a['creditSales']));

        return ['shifts' => $rows, 'cashiers' => $cashiers];
    }
}
