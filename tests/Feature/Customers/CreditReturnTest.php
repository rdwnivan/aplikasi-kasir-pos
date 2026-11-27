<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\CustomerLedgerEntry;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Reports\SalesSummary;
use App\Reports\ShiftRecap;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    config(['pos.credit.default_limit' => 1_000_000]);
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['pin_hash' => Hash::make('1234')])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->minyak = Product::factory()->create(['name' => 'Minyak 1L', 'base_unit_id' => $pcs->id]);
    $this->minyakPcs = ProductUnit::factory()->for($this->minyak)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 15_000]);
    app(StockLedger::class)->receive($this->minyak, 50, '12000');

    $this->shift = app(OpenShift::class)->handle($this->cashier, 100_000);
    $this->customer = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Sari']);

    // 6 × 15.000 = 90.000: down payment 20.000, kasbon 70.000.
    $this->sale = app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->minyakPcs->id, 'qty' => 6]], paidAmount: 20_000, customer: $this->customer);
    $this->item = $this->sale->items->first();
    $this->returns = app(ProcessSaleReturn::class);
});

it('takes a return off the debt first, with no cash leaving the drawer', function () {
    $return = $this->returns->handle($this->cashier, $this->sale, [$this->item->id => 2], '1234', 'Bocor');

    expect($return)->total->toBe(30_000)->credit_amount->toBe(30_000)
        ->and($return->cashRefund())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(40_000)
        ->and($this->shift->fresh()->refundsTotal())->toBe(0)
        ->and($this->shift->fresh()->expectedCash())->toBe(120_000)
        ->and($this->minyak->fresh()->stock_on_hand)->toBe(46);

    $entry = CustomerLedgerEntry::where('type', LedgerEntryType::SaleReturn)->sole();
    expect($entry)->amount->toBe(-30_000)->sale_id->toBe($this->sale->id)->reference_id->toBe($return->id);
});

it('refunds in cash only what the debt no longer covers', function () {
    app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 50_000); // owed on this sale: 20.000

    $return = $this->returns->handle($this->cashier, $this->sale, [$this->item->id => 3], '1234');

    expect($return)->total->toBe(45_000)->credit_amount->toBe(20_000)
        ->and($return->cashRefund())->toBe(25_000)
        ->and($this->customer->fresh()->balance)->toBe(0)
        // 100.000 float + 20.000 down payment + 50.000 paid − 25.000 refunded
        ->and($this->shift->fresh()->expectedCash())->toBe(145_000);

    $recap = ShiftRecap::build(DateRange::of('2026-11-27', '2026-11-27'))['shifts'][0];
    expect($recap)->refunds->toBe(25_000)->expectedCash->toBe(145_000);
});

it('clears the debt exactly when everything comes back', function () {
    $this->returns->handle($this->cashier, $this->sale, [$this->item->id => 6], '1234');

    // 90.000 back: 70.000 off the debt, the 20.000 down payment in cash.
    expect($this->customer->fresh()->balance)->toBe(0)
        ->and($this->shift->fresh()->refundsTotal())->toBe(20_000);
});

it('still reduces sales by the full return value', function () {
    $this->returns->handle($this->cashier, $this->sale, [$this->item->id => 2], '1234');

    $totals = SalesSummary::build(DateRange::of('2026-11-27', '2026-11-27'))['totals'];
    expect($totals)->refunds->toBe(30_000)->gross->toBe(90_000);
});

it('still needs the owner PIN', function () {
    expect(fn () => $this->returns->handle($this->cashier, $this->sale, [$this->item->id => 1], '9999'))
        ->toThrow(ValidationException::class, 'PIN owner salah.');
    expect($this->customer->fresh()->balance)->toBe(70_000);
});
