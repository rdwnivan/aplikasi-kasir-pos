<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Customers\ReceiveCustomerPayment;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Shift;
use App\Reports\Receivables;
use App\Services\Receivables\CustomerLedger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

/** Pelunasan kasbon at the till: cash into the receiving user's open shift (DESIGN.md §17). */
class CustomerPaymentController extends Controller
{
    public function create(Request $request, CustomerLedger $ledger): Response|RedirectResponse
    {
        $shift = Shift::query()->open()->where('user_id', $request->user()->id)->first();

        if ($shift === null) {
            return to_route('pos.index');
        }

        return Inertia::render('pos/payments', [
            'shift' => PosController::shiftSummary($shift),
            'debtors' => Customer::query()->where('balance', '>', 0)->orderBy('name')->get()
                ->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'balance' => $c->balance,
                    'active' => $c->isActive(),
                    'openSales' => array_map(fn (array $s) => [
                        'id' => $s['sale_id'],
                        'number' => $s['number'],
                        'at' => $s['completed_at']->toIso8601String(),
                        'ageDays' => Receivables::ageInDays(CarbonImmutable::parse($s['completed_at'])),
                        'credit' => $s['credit'],
                        'owed' => $s['owed'],
                    ], $ledger->openSales($c)),
                ])->values(),
            'customerId' => $request->integer('pelanggan') ?: null,
            'lastPayment' => fn () => self::lastPayment($request),
        ]);
    }

    public function store(Request $request, ReceiveCustomerPayment $receive): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ], attributes: ['customer_id' => 'pelanggan', 'amount' => 'jumlah']);

        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $payment = $receive->handle($request->user(), $customer, (int) $data['amount'], $data['note'] ?? null);

        $request->session()->flash('last_payment_id', $payment->id);

        return to_route('pos.payments.create');
    }

    public function receipt(Request $request, CustomerPayment $payment): View
    {
        abort_unless($payment->user_id === $request->user()->id || $request->user()->hasRole(Role::Owner->value), 403);

        $payment->load(['customer', 'user', 'entries' => fn ($q) => $q->with('sale')->orderBy('id')]);

        return view('receipts.customer-payment', [
            'payment' => $payment,
            'allocations' => $payment->entries->map(fn (CustomerLedgerEntry $e) => [
                'number' => $e->sale->number,
                'paid' => -$e->amount,
                // What is left on that kasbon right after this payment.
                'left' => (int) CustomerLedgerEntry::query()->where('sale_id', $e->sale_id)->where('id', '<=', $e->id)->sum('amount'),
            ]),
            'balanceAfter' => $payment->entries->last()->balance_after ?? 0,
            'store' => config('pos.store'),
            'width' => $request->integer('w') === 80 ? 80 : 58,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function lastPayment(Request $request): ?array
    {
        $id = $request->session()->get('last_payment_id');
        $payment = is_numeric($id) ? CustomerPayment::query()->with('customer')->where('user_id', $request->user()->id)->find((int) $id) : null;

        return $payment === null ? null : [
            'id' => $payment->id,
            'number' => $payment->number,
            'amount' => $payment->amount,
            'customer' => $payment->customer->name,
            'balance' => (int) $payment->entries()->orderByDesc('id')->value('balance_after'),
        ];
    }
}
