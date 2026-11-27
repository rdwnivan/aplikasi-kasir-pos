<?php

namespace App\Http\Controllers\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\SetCustomerActive;
use App\Actions\Customers\UpdateCustomer;
use App\Actions\Customers\WriteOffCustomerDebt;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Reports\Receivables;
use App\Services\Receivables\CustomerLedger;
use App\Support\Rupiah;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pelanggan (DESIGN.md §17). Owners and cashiers see the list and each customer's kasbon
 * card; only owners add from here, change, deactivate and write off.
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        $filter = in_array($request->query('status'), ['berutang', 'lewat-30', 'nonaktif'], true) ? (string) $request->query('status') : null;

        // Oldest kasbon still open per customer: the earliest sale whose ledger rows sum > 0.
        $oldest = CustomerLedgerEntry::query()
            ->join('sales', 'sales.id', '=', 'customer_ledger_entries.sale_id')
            ->groupBy('customer_ledger_entries.customer_id', 'customer_ledger_entries.sale_id', 'sales.completed_at')
            ->havingRaw('SUM(customer_ledger_entries.amount) > 0')
            ->selectRaw('customer_ledger_entries.customer_id AS customer_id, sales.completed_at AS at')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->min('at'));

        $customers = Customer::query()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('phone', 'like', '%'.(Customer::normalizePhone($search) ?? $search).'%')))
            ->when($filter === 'nonaktif', fn (Builder $q) => $q->whereNotNull('deactivated_at'), fn (Builder $q) => $filter === null ? $q : $q->whereNull('deactivated_at'))
            ->when(in_array($filter, ['berutang', 'lewat-30'], true), fn (Builder $q) => $q->where('balance', '>', 0))
            ->orderByDesc('balance')
            ->orderBy('name')
            ->get()
            ->filter(fn (Customer $c) => $filter !== 'lewat-30'
                || (isset($oldest[$c->id]) && Receivables::ageInDays(CarbonImmutable::parse($oldest[$c->id])) > 30))
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'address' => $c->address,
                'note' => $c->note,
                'balance' => $c->balance,
                'creditLimit' => $c->credit_limit,
                'active' => $c->isActive(),
                'oldestAt' => isset($oldest[$c->id]) ? CarbonImmutable::parse($oldest[$c->id])->toIso8601String() : null,
                // Calendar days in Asia/Jakarta, the same count as the receivables report.
                'oldestDays' => isset($oldest[$c->id]) ? Receivables::ageInDays(CarbonImmutable::parse($oldest[$c->id])) : null,
            ])
            ->values();

        return Inertia::render('customers/index', [
            'customers' => $customers,
            'filters' => ['q' => $search, 'status' => $filter],
            'canManage' => $request->user()->hasRole(Role::Owner->value),
            'defaultLimit' => (int) config('pos.credit.default_limit'),
        ]);
    }

    public function show(Request $request, Customer $customer, CustomerLedger $ledger): Response
    {
        $customer->load('creator');
        $entries = $customer->entries()->with(['sale', 'user', 'reference'])->orderByDesc('id')->limit(300)->get();

        return Inertia::render('customers/show', [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'note' => $customer->note,
                'balance' => $customer->balance,
                'creditLimit' => $customer->credit_limit,
                'available' => $customer->availableCredit(),
                'active' => $customer->isActive(),
                'createdBy' => $customer->creator?->name,
                'createdAt' => $customer->created_at?->toIso8601String(),
            ],
            'openSales' => array_map(fn (array $s) => [
                'id' => $s['sale_id'],
                'number' => $s['number'],
                'at' => $s['completed_at']->toIso8601String(),
                'total' => $s['total'],
                'credit' => $s['credit'],
                'owed' => $s['owed'],
            ], $ledger->openSales($customer)),
            'entries' => $entries->map(fn (CustomerLedgerEntry $e) => [
                'id' => $e->id,
                'at' => $e->created_at->toIso8601String(),
                'type' => $e->type->value,
                'label' => $e->type->label(),
                'saleId' => $e->sale_id,
                'saleNumber' => $e->sale->number,
                'document' => match (true) {
                    $e->reference instanceof CustomerPayment => ['kind' => 'payment', 'id' => $e->reference->id, 'number' => $e->reference->number],
                    $e->reference instanceof SaleReturn => ['kind' => 'return', 'id' => $e->reference->id, 'number' => $e->reference->number],
                    $e->reference instanceof Sale => ['kind' => 'sale', 'id' => $e->reference->id, 'number' => $e->reference->number],
                    default => null,
                },
                'note' => $e->note,
                'user' => $e->user?->name,
                'amount' => $e->amount,
                'balanceAfter' => $e->balance_after,
            ])->values(),
            'canManage' => $request->user()->hasRole(Role::Owner->value),
        ]);
    }

    public function store(Request $request, CreateCustomer $create): RedirectResponse
    {
        $customer = $create->handle($request->user(), $this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Pelanggan {$customer->name} ditambahkan."]);

        return back();
    }

    public function update(Request $request, Customer $customer, UpdateCustomer $update): RedirectResponse
    {
        $update->handle($request->user(), $customer, $this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data pelanggan disimpan.']);

        return back();
    }

    public function deactivate(Request $request, Customer $customer, SetCustomerActive $set): RedirectResponse
    {
        $set->handle($request->user(), $customer, active: false);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$customer->name} dinonaktifkan. Utangnya tetap bisa dilunasi."]);

        return back();
    }

    public function reactivate(Request $request, Customer $customer, SetCustomerActive $set): RedirectResponse
    {
        $set->handle($request->user(), $customer, active: true);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$customer->name} diaktifkan lagi."]);

        return back();
    }

    public function writeOff(Request $request, Customer $customer, WriteOffCustomerDebt $writeOff): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'sale_id' => ['nullable', 'integer'],
        ], attributes: ['reason' => 'alasan']);

        $sale = isset($data['sale_id']) ? Sale::query()->findOrFail((int) $data['sale_id']) : null;
        $done = $writeOff->handle($request->user(), $customer, $data['reason'], $sale);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Utang '.Rupiah::format($done->amount)." {$customer->name} dihapuskan."]);

        return back();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'credit_limit' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], attributes: ['name' => 'nama pelanggan', 'phone' => 'nomor HP', 'address' => 'alamat', 'note' => 'catatan', 'credit_limit' => 'batas kasbon']);
    }
}
