<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    config(['pos.credit.default_limit' => 500_000]);
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
    $this->warehouse = User::factory()->create()->assignRole(Role::Warehouse->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Kopi Kapal Api', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 12_500]);
    app(StockLedger::class)->receive($product, 50, '10000');

    $this->shift = app(OpenShift::class)->handle($this->cashier, 100_000);
    $this->customer = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Sari', 'phone' => '081234567890']);
});

function kasbon(object $test, int $qty = 2, int $downPayment = 0): Sale
{
    return app(CompleteSale::class)->handle($test->cashier, [['product_unit_id' => $test->unit->id, 'qty' => $qty]], paidAmount: $downPayment, customer: $test->customer);
}

describe('checkout', function () {
    it('takes kasbon from the till and shows it in the success dialog', function () {
        $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
            'items' => [['product_unit_id' => $this->unit->id, 'qty' => 2]],
            'payment_method' => 'credit',
            'customer_id' => $this->customer->id,
            'paid_amount' => 5_000,
        ])->assertRedirect(route('pos.index'))->assertSessionHasNoErrors();

        $sale = Sale::sole();
        expect($sale->payment_method)->toBe(PaymentMethod::Credit)->and($sale->credit_amount)->toBe(20_000);

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('lastSale.method', 'credit')
                ->where('lastSale.creditAmount', 20_000)
                ->where('lastSale.cashAmount', 5_000)
                ->where('lastSale.customer.name', 'Bu Sari')
                ->where('lastSale.customer.balance', 20_000));
    });

    it('needs a customer for kasbon', function () {
        $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
            'items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]],
            'payment_method' => 'credit',
            'paid_amount' => 0,
        ])->assertSessionHasErrors('customer_id');

        expect(Sale::count())->toBe(0);
    });

    it('lists active customers on the till with what they owe and may still borrow', function () {
        kasbon($this);
        Customer::factory()->create(['name' => 'Tutup', 'deactivated_at' => now()]);

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('customers', 1)
                ->where('customers.0.name', 'Bu Sari')
                ->where('customers.0.phone', '081234567890')
                ->where('customers.0.balance', 25_000)
                ->where('customers.0.available', 475_000));
    });

    it('lets a cashier add a customer from the till, with the default limit', function () {
        $this->actingAs($this->cashier)->post(route('pos.customers.store'), ['name' => 'Pak RT', 'phone' => '0813', 'credit_limit' => 5_000_000])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect(Customer::where('name', 'Pak RT')->sole())->credit_limit->toBe(500_000)->created_by->toBe($this->cashier->id);
    });
});

describe('payments at the till', function () {
    it('shows who owes and takes a payment, then prints a receipt with the allocation', function () {
        $sale = kasbon($this);

        $this->actingAs($this->cashier)->get(route('pos.payments.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/payments')
                ->has('debtors', 1)
                ->where('debtors.0.name', 'Bu Sari')
                ->where('debtors.0.balance', 25_000)
                ->where('debtors.0.openSales.0.number', $sale->number)
                ->where('debtors.0.openSales.0.owed', 25_000));

        $this->actingAs($this->cashier)->post(route('pos.payments.store'), ['customer_id' => $this->customer->id, 'amount' => 10_000])
            ->assertRedirect(route('pos.payments.create', ['pelanggan' => $this->customer->id]))
            ->assertSessionHasNoErrors();

        $payment = CustomerPayment::sole();

        $this->actingAs($this->cashier)->get(route('pos.payments.receipt', $payment))
            ->assertOk()
            ->assertSeeText('PAY-20261127-0001')
            ->assertSeeText('Bu Sari')
            ->assertSeeText($sale->number)
            ->assertSeeText('Sisa utang')
            ->assertSeeText('15.000');
    });

    it('keeps the customer selected after a cash payment and shows the success dialog once', function () {
        kasbon($this);

        $this->actingAs($this->cashier)->post(route('pos.payments.store'), ['customer_id' => $this->customer->id, 'amount' => 10_000])
            ->assertRedirect(route('pos.payments.create', ['pelanggan' => $this->customer->id]));

        $payment = CustomerPayment::sole();

        $this->actingAs($this->cashier)->get(route('pos.payments.create', ['pelanggan' => $this->customer->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customerId', $this->customer->id)
                ->where('lastPayment.id', $payment->id)
                ->where('lastPayment.balance', 15_000));

        // The flash is spent: a reload keeps the customer but not the dialog.
        $this->actingAs($this->cashier)->get(route('pos.payments.create', ['pelanggan' => $this->customer->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customerId', $this->customer->id)
                ->where('lastPayment', null));
    });

    it('shows the shift drawer including payments', function () {
        kasbon($this);
        app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000);

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('shift.paymentsCount', 1)
                ->where('shift.paymentsTotal', 10_000)
                ->where('shift.expectedCash', 110_000));
    });

    it('keeps another cashier from printing someone else\'s payment receipt', function () {
        kasbon($this);
        $payment = app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000);
        $other = User::factory()->create()->assignRole(Role::Cashier->value);

        $this->actingAs($other)->get(route('pos.payments.receipt', $payment))->assertForbidden();
        $this->actingAs($this->owner)->get(route('pos.payments.receipt', $payment))->assertOk();
    });
});

describe('receipts', function () {
    it('prints kasbon, down payment and the balance as of that sale', function () {
        $sale = kasbon($this, 2, 5_000);
        app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 20_000);

        $this->actingAs($this->cashier)->get(route('pos.receipts.show', $sale))
            ->assertOk()
            ->assertSeeText('Bu Sari')
            ->assertSeeText('Uang muka')
            ->assertSeeText('Kasbon')
            ->assertSeeText('Sisa utang')
            ->assertSeeText('20.000'); // balance after this sale, not the 0 it is now
    });
});

describe('customer pages', function () {
    it('lists customers and the ledger card for owner and cashier, not warehouse', function () {
        kasbon($this);

        foreach ([$this->owner, $this->cashier] as $user) {
            $this->actingAs($user)->get(route('customers.index'))
                ->assertInertia(fn (Assert $page) => $page->component('customers/index')->where('customers.0.balance', 25_000));
            $this->actingAs($user)->get(route('customers.show', $this->customer))
                ->assertInertia(fn (Assert $page) => $page->component('customers/show')->has('entries', 1)->where('entries.0.balanceAfter', 25_000));
        }

        $this->actingAs($this->warehouse)->get(route('customers.index'))->assertForbidden();
        $this->actingAs($this->warehouse)->get(route('pos.payments.create'))->assertForbidden();
    });

    it('ages the oldest open kasbon in calendar days and filters those past 30', function () {
        Carbon::setTestNow('2026-10-27 23:30:00'); // 31 calendar days before 27 Nov, < 31 × 24 h
        kasbon($this);
        $fresh = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Baru']);
        Carbon::setTestNow('2026-11-27 10:00:00');
        app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 1]], paidAmount: 0, customer: $fresh);

        $this->actingAs($this->owner)->get(route('customers.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.0.name', 'Bu Sari')
                ->where('customers.0.oldestDays', 31)
                ->where('customers.1.oldestDays', 0));

        $this->actingAs($this->owner)->get(route('customers.index', ['status' => 'lewat-30']))
            ->assertInertia(fn (Assert $page) => $page->has('customers', 1)->where('customers.0.name', 'Bu Sari'));
    });

    it('lets only the owner change, deactivate and write off', function () {
        kasbon($this);
        $routes = [
            ['put', route('customers.update', $this->customer), ['name' => 'Bu Sari', 'credit_limit' => 2_000_000]],
            ['post', route('customers.deactivate', $this->customer), []],
            ['post', route('customers.write-off', $this->customer), ['reason' => 'Pindah']],
            ['get', route('reports.receivables'), []],
            ['get', route('reports.export.receivables'), []],
        ];

        foreach ([$this->cashier, $this->warehouse] as $user) {
            foreach ($routes as [$method, $url, $data]) {
                $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
            }
        }

        $this->actingAs($this->owner)->put(route('customers.update', $this->customer), ['name' => 'Bu Sari W.', 'phone' => '0812', 'credit_limit' => 2_000_000])->assertSessionHasNoErrors();
        expect($this->customer->fresh())->name->toBe('Bu Sari W.')->credit_limit->toBe(2_000_000);

        $this->actingAs($this->owner)->post(route('customers.write-off', $this->customer), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->owner)->post(route('customers.write-off', $this->customer), ['reason' => 'Pindah kota'])->assertSessionHasNoErrors();
        expect($this->customer->fresh()->balance)->toBe(0);

        $this->actingAs($this->owner)->post(route('customers.deactivate', $this->customer))->assertSessionHasNoErrors();
        expect($this->customer->fresh()->deactivated_at)->not->toBeNull();
        $this->actingAs($this->owner)->post(route('customers.reactivate', $this->customer))->assertSessionHasNoErrors();
        expect($this->customer->fresh()->deactivated_at)->toBeNull();
    });

    it('filters sale history by kasbon', function () {
        $sale = kasbon($this);
        app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 1]], paidAmount: 12_500);

        $this->actingAs($this->owner)->get(route('pos.sales.index', ['method' => 'credit']))
            ->assertInertia(fn (Assert $page) => $page->has('sales.data', 1)->where('sales.data.0.number', $sale->number)->where('sales.data.0.customer', 'Bu Sari'));
    });
});
