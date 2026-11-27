<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\HoldSale;
use App\Actions\Sales\OpenShift;
use App\Enums\LedgerEntryType;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    config(['pos.credit.default_limit' => 500_000]);
    $this->seed(RoleSeeder::class);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->beras = Product::factory()->create(['name' => 'Beras 5kg', 'base_unit_id' => $pcs->id]);
    $this->berasPcs = ProductUnit::factory()->for($this->beras)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 77_700]);
    app(StockLedger::class)->receive($this->beras, 20, '60000');

    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $this->shift = app(OpenShift::class)->handle($this->cashier, 100_000);
    $this->customer = app(CreateCustomer::class)->handle($this->cashier, ['name' => 'Bu Sari', 'phone' => '0812-3456-7890']);
    $this->checkout = app(CompleteSale::class);
});

function creditSale(object $test, int $qty = 1, int $downPayment = 0, ?Customer $customer = null): Sale
{
    return $test->checkout->handle(
        $test->cashier,
        [['product_unit_id' => $test->berasPcs->id, 'qty' => $qty]],
        paidAmount: $downPayment,
        customer: $customer ?? $test->customer,
    );
}

it('records a full kasbon: completed, stock out at average HPP, debt on the customer', function () {
    $sale = creditSale($this, 2);

    expect($sale)
        ->status->toBe(SaleStatus::Completed)
        ->payment_method->toBe(PaymentMethod::Credit)
        ->customer_id->toBe($this->customer->id)
        ->total->toBe(155_400)
        ->credit_amount->toBe(155_400)
        ->paid_amount->toBe(0)
        ->change_amount->toBe(0)
        ->cogs_total->toBe('120000.0000')
        ->and($sale->cashPart())->toBe(0)
        ->and($sale->creditPart())->toBe(155_400);

    expect($this->beras->fresh()->stock_on_hand)->toBe(18)
        ->and(StockMovement::where('type', StockMovementType::Sale)->count())->toBe(1)
        ->and($this->customer->fresh()->balance)->toBe(155_400);

    $entry = CustomerLedgerEntry::sole();
    expect($entry)
        ->type->toBe(LedgerEntryType::Sale)
        ->amount->toBe(155_400)
        ->balance_after->toBe(155_400)
        ->sale_id->toBe($sale->id)
        ->user_id->toBe($this->cashier->id);
});

it('takes a cash down payment, which goes into the drawer', function () {
    $sale = creditSale($this, 1, downPayment: 20_000);

    expect($sale)
        ->credit_amount->toBe(57_700)
        ->paid_amount->toBe(20_000)
        ->and($sale->cashPart())->toBe(20_000)
        ->and($this->customer->fresh()->balance)->toBe(57_700)
        ->and($this->shift->fresh()->expectedCash())->toBe(120_000)
        ->and($this->shift->fresh()->cashSalesCount())->toBe(1);
});

it('counts only sales that put cash in the drawer as cash sales, so kasbon without a down payment is left out', function () {
    $this->checkout->handle($this->cashier, [['product_unit_id' => $this->berasPcs->id, 'qty' => 1]], paidAmount: 77_700);
    creditSale($this, 1); // no down payment: no cash
    creditSale($this, 1, downPayment: 20_000);

    $shift = $this->shift->fresh();
    expect($shift->cashSalesCount())->toBe(2)
        ->and($shift->cashSalesTotal())->toBe(97_700);

    // The till header and the Tutup shift page read the same number.
    $this->actingAs($this->cashier)->get(route('pos.shift.edit'))
        ->assertInertia(fn ($page) => $page->where('shift.salesCount', 2)->where('shift.cashSalesTotal', 97_700));
});

it('refuses kasbon over the credit limit, with what is left of it', function () {
    creditSale($this, 5); // 388.500 of 500.000

    expect(fn () => creditSale($this, 2))
        ->toThrow(ValidationException::class, 'Batas kasbon Bu Sari Rp 500.000, sisa Rp 111.500.');

    expect(Sale::count())->toBe(1)
        ->and($this->beras->fresh()->stock_on_hand)->toBe(15)
        ->and(CustomerLedgerEntry::count())->toBe(1)
        ->and($this->customer->fresh()->balance)->toBe(388_500);
});

it('allows up to exactly the limit, counting only the part on credit', function () {
    $this->customer->forceFill(['credit_limit' => 57_700])->save();

    creditSale($this, 2, downPayment: 97_700); // 155.400 − 97.700 = 57.700 on credit

    expect($this->customer->fresh()->balance)->toBe(57_700);
});

it('refuses customers with a zero limit or who are deactivated', function (string $case) {
    $case === 'zero'
        ? $this->customer->forceFill(['credit_limit' => 0])->save()
        : $this->customer->forceFill(['deactivated_at' => now()])->save();

    expect(fn () => creditSale($this))->toThrow(ValidationException::class);
    expect(Sale::count())->toBe(0)->and(CustomerLedgerEntry::count())->toBe(0);
})->with(['zero', 'deactivated']);

it('refuses a down payment that covers the whole total', function () {
    expect(fn () => creditSale($this, 1, downPayment: 77_700))
        ->toThrow(ValidationException::class, 'Uang muka harus kurang dari total belanja.');
    expect(Sale::count())->toBe(0);
});

it('rolls the debt back when stock runs out', function () {
    $this->customer->forceFill(['credit_limit' => 10_000_000])->save();

    expect(fn () => creditSale($this, 21))->toThrow(Exception::class);
    expect(Sale::count())->toBe(0)
        ->and(CustomerLedgerEntry::count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(0);
});

it('turns a held cart into kasbon and drops the held cart', function () {
    $held = app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->berasPcs->id, 'qty' => 1]], label: 'Bu Sari');

    $sale = $this->checkout->handle($this->cashier, [['product_unit_id' => $this->berasPcs->id, 'qty' => 1]], paidAmount: 0, fromHeld: $held, customer: $this->customer);

    expect(Sale::whereKey($held->id)->exists())->toBeFalse()
        ->and($sale->payment_method)->toBe(PaymentMethod::Credit);
});

it('lets a cashier add a customer, always with the default limit', function () {
    $c = app(CreateCustomer::class)->handle($this->cashier, ['name' => '  Pak RT ', 'phone' => '+62 812 1111', 'credit_limit' => 9_000_000]);

    expect($c)->name->toBe('Pak RT')->phone->toBe('628121111')->credit_limit->toBe(500_000)->balance->toBe(0)->created_by->toBe($this->cashier->id);

    $byOwner = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Ani', 'credit_limit' => 1_000_000]);
    expect($byOwner->credit_limit)->toBe(1_000_000)->and($byOwner->phone)->toBeNull();
});

it('refuses a phone number that another customer already has', function () {
    app(CreateCustomer::class)->handle($this->cashier, ['name' => 'Kembar', 'phone' => '081234567890']);
})->throws(ValidationException::class, 'Nomor HP ini sudah dipakai Bu Sari.');

it('counts kasbon as sales when sold, separate from cash and QRIS', function () {
    creditSale($this, 1, downPayment: 20_000);
    $this->checkout->handle($this->cashier, [['product_unit_id' => $this->berasPcs->id, 'qty' => 1]], paidAmount: 77_700);

    $day = DateRange::of('2026-11-27', '2026-11-27');
    $totals = SalesSummary::build($day)['totals'];

    expect($totals)
        ->transactions->toBe(2)
        ->gross->toBe(155_400)
        ->cash->toBe(97_700)
        ->qris->toBe(0)
        ->credit->toBe(57_700)
        // Same profit as two cash sales: 2 × (70.000 − 60.000)
        ->grossProfit->toBe(20_000);

    $shift = ShiftRecap::build($day)['shifts'][0];
    expect($shift)->cashSales->toBe(97_700)->creditSales->toBe(57_700)->payments->toBe(0)->expectedCash->toBe(197_700);
});

it('closes the shift with the down payment in the drawer', function () {
    creditSale($this, 1, downPayment: 20_000);

    $closed = app(CloseShift::class)->handle($this->shift, 120_000);

    expect($closed)->expected_cash->toBe(120_000)->cash_difference->toBe(0);
});
