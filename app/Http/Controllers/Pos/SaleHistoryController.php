<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\User;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Riwayat transaksi. Owners see every sale; cashiers see the sales of their own shifts,
 * one shift at a time (their current or latest shift by default).
 */
class SaleHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isOwner = $user->hasRole(Role::Owner->value);
        $search = trim((string) $request->query('q', ''));

        $query = Sale::query()
            ->with(['user', 'shift'])
            ->withCount('items')
            ->withSum('returns as returned_total', 'total')
            ->whereNotIn('status', [SaleStatus::Held->value])
            ->when($search !== '', fn (Builder $q) => $q->where('number', 'like', '%'.mb_strtoupper($search).'%'))
            ->orderByDesc('id');

        if ($isOwner) {
            $range = DateRange::fromRequest($request, defaultDays: 1);
            $cashierId = $request->integer('cashier') ?: null;
            $method = PaymentMethod::tryFrom((string) $request->query('method'));

            $query->where('created_at', '>=', $range->startsAt())
                ->where('created_at', '<', $range->endsBefore())
                ->when($cashierId, fn (Builder $q, int $id) => $q->where('user_id', $id))
                ->when($method, fn (Builder $q, PaymentMethod $m) => $q->where('payment_method', $m->value));

            $filters = [...$range->toArray(), 'cashier' => $cashierId, 'method' => $method?->value, 'q' => $search];
            $options = [
                'cashiers' => User::role([Role::Cashier->value, Role::Owner->value])->orderBy('name')->get(['id', 'name']),
            ];
        } else {
            $shifts = Shift::query()->where('user_id', $user->id)->orderByDesc('opened_at')->limit(30)->get();
            $requested = $request->integer('shift');
            $shift = $shifts->firstWhere('id', $requested) ?? $shifts->first();

            $query->where('shift_id', $shift->id ?? 0);

            $filters = ['shift' => $shift?->id, 'q' => $search];
            $options = [
                'shifts' => $shifts->map(fn (Shift $s) => [
                    'id' => $s->id,
                    'openedAt' => $s->opened_at->toIso8601String(),
                    'closedAt' => $s->closed_at?->toIso8601String(),
                ])->values(),
            ];
        }

        $sales = $query->paginate(25)->withQueryString()->through(fn (Sale $s) => [
            'id' => $s->id,
            'number' => $s->number,
            'status' => $s->status->value,
            'at' => ($s->completed_at ?? $s->created_at)?->toIso8601String(),
            'cashier' => $s->user->name,
            'itemsCount' => $s->items_count,
            'total' => $s->total,
            'paymentMethod' => $s->payment_method?->value,
            'returnedTotal' => (int) $s->getAttribute('returned_total'),
        ]);

        return Inertia::render('pos/sales/index', [
            'sales' => $sales,
            'filters' => $filters,
            'isOwner' => $isOwner,
            ...$options,
        ]);
    }

    public function show(Request $request, Sale $sale): Response
    {
        $user = $request->user();
        abort_if($sale->status === SaleStatus::Held, 404);
        abort_unless($user->hasRole(Role::Owner->value) || $sale->user_id === $user->id, 403);

        $sale->load([
            'user',
            'items' => fn ($q) => $q->withSum('returnItems as returned_qty', 'qty'),
            'returns' => fn ($q) => $q->with(['user', 'approver', 'items.saleItem'])->orderBy('id'),
        ]);
        $shares = ProcessSaleReturn::lineShares($sale, $sale->items->keyBy('id')->all());

        return Inertia::render('pos/sales/show', [
            'sale' => [
                'id' => $sale->id,
                'number' => $sale->number,
                'status' => $sale->status->value,
                'at' => ($sale->completed_at ?? $sale->created_at)?->toIso8601String(),
                'cashier' => $sale->user->name,
                'subtotal' => $sale->subtotal,
                'discountAmount' => $sale->discount_amount,
                'taxAmount' => $sale->tax_amount,
                'pricesIncludeTax' => $sale->prices_include_tax,
                'total' => $sale->total,
                'paymentMethod' => $sale->payment_method?->value,
                'paidAmount' => $sale->paid_amount,
                'changeAmount' => $sale->change_amount,
                'cashAmount' => $sale->cashPart(),
                'qrisAmount' => $sale->qrisPart(),
                'items' => $sale->items->map(fn (SaleItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'unit' => $item->unit_name,
                    'qty' => $item->qty,
                    'unitPrice' => $item->unit_price,
                    'discountAmount' => $item->discount_amount,
                    'lineTotal' => $item->line_total,
                    'paidShare' => $shares[$item->id] ?? 0,
                    'returnedQty' => (int) $item->getAttribute('returned_qty'),
                ])->values(),
                'returns' => $sale->returns->map(fn (SaleReturn $r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'at' => $r->created_at?->toIso8601String(),
                    'user' => $r->user->name,
                    'approver' => $r->approver->name,
                    'total' => $r->total,
                    'reason' => $r->reason,
                    'items' => $r->items->map(fn (SaleReturnItem $i) => [
                        'name' => $i->saleItem->product_name,
                        'unit' => $i->saleItem->unit_name,
                        'qty' => $i->qty,
                        'amount' => $i->amount,
                    ])->values(),
                ])->values(),
            ],
            'canReturn' => $sale->status === SaleStatus::Completed
                && Shift::query()->open()->where('user_id', $user->id)->exists(),
        ]);
    }
}
