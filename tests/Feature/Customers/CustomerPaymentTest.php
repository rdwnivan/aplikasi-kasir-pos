<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Customers\WriteOffCustomerDebt;
use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CloseShiftForUser;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Services\Inventory\StockLedger;
use App\Services\Receivables\CustomerLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    config(['pos.credit.default_limit' => 1_000_000]);
    $this->seed(RoleSeeder::class);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->gula = Product::factory()->create(['name' => 'Gula', 'base_unit_id' => $pcs->id]);
    $this->gulaPcs = ProductUnit::factory()->for($this->gula)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 10_000]);
    app(StockLedger::class)->receive($this->gula, 100, '8000');

    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
    $this->other = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Cashier->value);
    $this->shift = app(OpenShift::class)->handle($this->cashier, 50_000);
    $this->customer = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Sari']);

    // Three kasbon on three days: 30.000, 20.000, 50.000 = 100.000.
    $this->sales = [];
    foreach ([[3, '2026-11-20 10:00'], [2, '2026-11-21 10:00'], [5, '2026-11-22 10:00']] as [$qty, $at]) {
        Carbon::setTestNow($at);
        $this->sales[] = app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->gulaPcs->id, 'qty' => $qty]], paidAmount: 0, customer: $this->customer);
    }
    Carbon::setTestNow('2026-11-25 09:00:00');
    $this->pay = app(ReceiveCustomerPayment::class);
});

/** @return array<int, int> sale id => what is still owed on it */
function owedPerSale(): array
{
    return CustomerLedgerEntry::query()->groupBy('sale_id')->orderBy('sale_id')->selectRaw('sale_id, SUM(amount) AS owed')
        ->pluck('owed', 'sale_id')->map(fn ($v) => (int) $v)->all();
}

it('allocates a part payment to the oldest kasbon first', function () {
    $payment = $this->pay->handle($this->cashier, $this->customer, 40_000, 'Titip anaknya');

    expect($payment)
        ->number->toBe('PAY-20261125-0001')
        ->amount->toBe(40_000)
        ->shift_id->toBe($this->shift->id)
        ->user_id->toBe($this->cashier->id)
        ->method->toBe('cash');

    expect($this->customer->fresh()->balance)->toBe(60_000)
        ->and(owedPerSale())->toBe([
            $this->sales[0]->id => 0,
            $this->sales[1]->id => 10_000,
            $this->sales[2]->id => 50_000,
        ]);

    $entries = $payment->entries()->orderBy('id')->get();
    expect($entries->pluck('amount')->all())->toBe([-30_000, -10_000])
        ->and($entries->pluck('type')->unique()->all())->toBe([LedgerEntryType::Payment])
        ->and($entries->last()->balance_after)->toBe(60_000);
});

it('pays everything off', function () {
    $this->pay->handle($this->cashier, $this->customer, 100_000);

    expect($this->customer->fresh()->balance)->toBe(0)
        ->and(array_values(owedPerSale()))->toBe([0, 0, 0]);
});

it('refuses more than is owed, zero, or without an open shift', function (int $amount, ?string $who) {
    $actor = $who === 'other' ? $this->other : $this->cashier;

    expect(fn () => $this->pay->handle($actor, $this->customer, $amount))->toThrow(ValidationException::class);
    expect(CustomerPayment::count())->toBe(0)->and($this->customer->fresh()->balance)->toBe(100_000);
})->with([
    'over the balance' => [100_001, null],
    'zero' => [0, null],
    'no open shift' => [10_000, 'other'],
]);

it('puts the cash into the drawer of whoever received it, not where the kasbon was given', function () {
    $otherShift = app(OpenShift::class)->handle($this->other, 0);
    $this->pay->handle($this->other, $this->customer, 25_000);

    expect($otherShift->fresh()->expectedCash())->toBe(25_000)
        ->and($this->shift->fresh()->expectedCash())->toBe(50_000);

    $recap = collect(ShiftRecap::build(DateRange::of('2026-11-20', '2026-11-25'))['shifts'])->keyBy('id');
    expect($recap[$otherShift->id])->payments->toBe(25_000)->expectedCash->toBe(25_000)
        ->and($recap[$this->shift->id])->creditSales->toBe(100_000)->payments->toBe(0);

    $closed = app(CloseShift::class)->handle($otherShift, 25_000);
    expect($closed->cash_difference)->toBe(0);
});

it('counts payments in an owner close too', function () {
    $this->pay->handle($this->cashier, $this->customer, 10_000);

    $closed = app(CloseShiftForUser::class)->handle($this->owner, $this->shift, 60_000, 'Kasir pulang');

    expect($closed->expected_cash)->toBe(60_000);
});

it('does not touch sales or gross profit', function () {
    $before = SalesSummary::build(DateRange::of('2026-11-20', '2026-11-25'))['totals'];
    $this->pay->handle($this->cashier, $this->customer, 100_000);
    $after = SalesSummary::build(DateRange::of('2026-11-20', '2026-11-25'))['totals'];

    expect($after)->toBe($before);
});

describe('write-off', function () {
    it('lets an owner write off one kasbon, with a reason', function () {
        $writeOff = app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, 'Pindah kota', $this->sales[1]);

        expect($writeOff)->amount->toBe(20_000)->reason->toBe('Pindah kota')->user_id->toBe($this->owner->id)
            ->and($this->customer->fresh()->balance)->toBe(80_000)
            ->and(owedPerSale()[$this->sales[1]->id])->toBe(0)
            ->and(CustomerLedgerEntry::where('type', LedgerEntryType::WriteOff)->sum('amount'))->toEqual(-20_000);
    });

    it('writes off everything that is left, oldest first', function () {
        $this->pay->handle($this->cashier, $this->customer, 35_000);
        $writeOff = app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, 'Meninggal dunia');

        expect($writeOff->amount)->toBe(65_000)->and($this->customer->fresh()->balance)->toBe(0)
            ->and(array_values(owedPerSale()))->toBe([0, 0, 0]);
    });

    it('needs an owner and a reason, and something to write off', function (string $case) {
        $run = match ($case) {
            'cashier' => fn () => app(WriteOffCustomerDebt::class)->handle($this->cashier, $this->customer, 'Alasan'),
            'no reason' => fn () => app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, '  '),
            'paid sale' => function () {
                $this->pay->handle($this->cashier, $this->customer, 30_000);
                app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, 'Alasan', $this->sales[0]);
            },
        };

        expect($run)->toThrow(ValidationException::class);
        expect(CustomerLedgerEntry::where('type', LedgerEntryType::WriteOff)->count())->toBe(0);
    })->with(['cashier', 'no reason', 'paid sale']);

    it('does not change gross profit', function () {
        $before = SalesSummary::build(DateRange::of('2026-11-20', '2026-11-25'))['totals'];
        app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, 'Tidak tertagih');

        expect(SalesSummary::build(DateRange::of('2026-11-20', '2026-11-25'))['totals'])->toBe($before);
    });
});

describe('ledger', function () {
    it('keeps entries immutable', function () {
        $entry = CustomerLedgerEntry::first();

        expect(fn () => $entry->forceFill(['amount' => 1])->save())->toThrow(LogicException::class)
            ->and(fn () => $entry->delete())->toThrow(LogicException::class);
    });

    it('lists what is owed per kasbon, oldest first', function () {
        $this->pay->handle($this->cashier, $this->customer, 35_000);

        $open = app(CustomerLedger::class)->openSales($this->customer);

        expect(array_column($open, 'sale_id'))->toBe([$this->sales[1]->id, $this->sales[2]->id])
            ->and(array_column($open, 'owed'))->toBe([15_000, 50_000]);
    });

    it('rebuilds balances from the ledger and finds a corrupted cache', function () {
        $this->pay->handle($this->cashier, $this->customer, 35_000);

        expect(Artisan::call('receivables:rebuild'))->toBe(0);

        $this->customer->forceFill(['balance' => 1])->save();
        expect(Artisan::call('receivables:rebuild'))->toBe(1)
            ->and(Artisan::output())->toContain('Bu Sari');

        expect(Artisan::call('receivables:rebuild', ['--fix' => true]))->toBe(0)
            ->and($this->customer->fresh()->balance)->toBe(65_000);
    });
});

it('keeps cash sales off the customer ledger', function () {
    $cash = app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->gulaPcs->id, 'qty' => 1]], paidAmount: 10_000);

    expect(Sale::find($cash->id)->customer_id)->toBeNull()
        ->and(CustomerLedgerEntry::where('sale_id', $cash->id)->exists())->toBeFalse();
});
