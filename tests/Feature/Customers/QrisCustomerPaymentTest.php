<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\QrisCustomerPayments;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Customers\StartQrisCustomerPayment;
use App\Actions\Customers\WriteOffCustomerDebt;
use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CloseShiftForUser;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\CustomerPaymentStatus;
use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Exports\ReportTables;
use App\Models\CustomerLedgerEntry;
use App\Models\CustomerPayment;
use App\Models\PaymentNotification;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Receivables;
use App\Reports\ShiftRecap;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Pelunasan kasbon by QRIS (DESIGN.md §18). The debt comes down only when Midtrans settles,
| and while a QR waits nothing else may take the same customer's debt down.
*/

const PAY_TEST_SERVER_KEY = 'SB-Mid-server-PAYTEST';

beforeEach(function () {
    Carbon::setTestNow('2026-11-20 10:00:00');
    config([
        'services.midtrans.server_key' => PAY_TEST_SERVER_KEY,
        'services.midtrans.signature_key' => null,
        'services.midtrans.is_production' => false,
        'pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true],
        'pos.credit.default_limit' => 1_000_000,
    ]);
    $this->seed(RoleSeeder::class);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->gula = Product::factory()->create(['name' => 'Gula', 'base_unit_id' => $pcs->id]);
    $this->gulaPcs = ProductUnit::factory()->for($this->gula)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 10_000]);
    app(StockLedger::class)->receive($this->gula, 100, '8000');

    $this->owner = User::factory()->create(['name' => 'Budi', 'pin_hash' => Hash::make('1234')])->assignRole(Role::Owner->value);
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

    $this->midtransFaked = false;
    $this->midtransStatus = 'pending';
    $this->midtransCharge = 'ok';
});

/**
 * Midtrans with its real status codes (DESIGN.md §8, "QRIS kedaluwarsa lewat polling"):
 * charge 201, pending 201, settlement 200, expire 407 (HTTP 200), unknown order 404.
 */
function fakeMidtransForPayments(string $statusAfter = 'pending', string $charge = 'ok'): void
{
    test()->midtransStatus = $statusAfter;
    test()->midtransCharge = $charge;

    if (test()->midtransFaked) {
        return;
    }
    test()->midtransFaked = true;

    Http::fake(function (HttpRequest $request) {
        $url = $request->url();

        if (str_ends_with($url, '/v2/charge')) {
            if (test()->midtransCharge === 'conflict') {
                return Http::response(['status_code' => '409', 'status_message' => 'Duplicate order ID. order_id has already been utilized previously.']);
            }

            return Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'trx-pay-1',
                'order_id' => $request['transaction_details']['order_id'],
                'gross_amount' => $request['transaction_details']['gross_amount'].'.00',
                'transaction_status' => 'pending',
                'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-pay-1/qr-code']],
                'qr_string' => '00020101021226PAY',
                'expiry_time' => '2026-11-25 09:15:00',
            ], 201);
        }

        if (test()->midtransStatus === 'down' && ! str_ends_with($url, '/v2/charge')) {
            return Http::response(['status_code' => '500', 'status_message' => 'Internal server error'], 500);
        }

        if (str_ends_with($url, '/status')) {
            $status = test()->midtransStatus;

            if ($status === 'missing') {
                return Http::response(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]);
            }

            return Http::response([
                'status_code' => match ($status) {
                    'settlement', 'cancel' => '200',
                    'expire' => '407',
                    default => '201',
                },
                'status_message' => $status === 'expire' ? 'Success, transaction is found' : 'Success',
                'transaction_status' => $status,
            ]);
        }

        if (str_ends_with($url, '/cancel')) {
            return Http::response(['status_code' => '200', 'transaction_status' => 'cancel']);
        }

        return Http::response([], 404);
    });
}

function startPaymentQris(int $amount = 40_000, ?User $as = null): CustomerPayment
{
    test()->actingAs($as ?? test()->cashier)
        ->post(route('pos.payments.qris.store'), ['customer_id' => test()->customer->id, 'amount' => $amount])
        ->assertRedirect(route('pos.payments.create', ['pelanggan' => test()->customer->id]))
        ->assertSessionHasNoErrors();

    return CustomerPayment::query()->latest('id')->firstOrFail();
}

/** @return array<string, string> */
function paymentNotification(CustomerPayment $payment, string $status, ?string $gross = null): array
{
    $gross ??= $payment->amount.'.00';
    $statusCode = $status === 'settlement' ? '200' : '202';

    return [
        'order_id' => $payment->number,
        'transaction_id' => 'trx-pay-1',
        'transaction_status' => $status,
        'status_code' => $statusCode,
        'gross_amount' => $gross,
        'payment_type' => 'qris',
        'signature_key' => hash('sha512', $payment->number.$statusCode.$gross.PAY_TEST_SERVER_KEY),
    ];
}

/** @return array<int, int> sale id => what is still owed on it */
function owedPerKasbon(): array
{
    return CustomerLedgerEntry::query()->groupBy('sale_id')->orderBy('sale_id')->selectRaw('sale_id, SUM(amount) AS owed')
        ->pluck('owed', 'sale_id')->map(fn ($v) => (int) $v)->all();
}

function pollPayment(CustomerPayment $payment, ?User $as = null): TestResponse
{
    Carbon::setTestNow(now()->addSeconds(5)); // past the 3-second check throttle

    return test()->actingAs($as ?? test()->cashier)->getJson(route('pos.payments.qris.show', $payment));
}

it('starts a waiting QRIS payment and leaves the debt alone', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    expect($payment)
        ->number->toBe('PAY-20261125-0001')
        ->method->toBe('qris')
        ->status->toBe(CustomerPaymentStatus::PendingPayment)
        ->amount->toBe(40_000)
        ->shift_id->toBe($this->shift->id)
        ->payment_reference->toBe('trx-pay-1')
        ->qr_url->toBe('https://api.sandbox.midtrans.com/v2/qris/trx-pay-1/qr-code')
        ->qr_string->toBe('00020101021226PAY')
        ->completed_at->toBeNull();
    expect($payment->payment_expires_at->toIso8601String())->toBe(Carbon::parse('2026-11-25 09:15:00', 'Asia/Jakarta')->toIso8601String());

    expect($payment->entries()->count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(100_000);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/charge')
        && $r['transaction_details']['order_id'] === 'PAY-20261125-0001'
        && $r['transaction_details']['gross_amount'] === 40_000
        && $r['payment_type'] === 'qris');
});

it('refuses a QRIS payment that cannot be taken', function (Closure $setup, int $amount, string $error) {
    fakeMidtransForPayments();
    $as = $setup->call($this);

    $this->actingAs($as ?? $this->cashier)
        ->post(route('pos.payments.qris.store'), ['customer_id' => $this->customer->id, 'amount' => $amount])
        ->assertSessionHasErrors();

    expect(session('errors')->all())->toContain($error)
        ->and(CustomerPayment::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'more than the debt' => [fn () => null, 100_001, 'Melebihi utang Bu Sari (Rp 100.000).'],
    'zero' => [fn () => null, 0, 'Jumlah pelunasan minimal Rp 1.'],
    'no open shift' => [fn () => test()->other, 10_000, 'Buka shift dulu sebelum bertransaksi.'],
    'no debt' => [function () {
        test()->customer = app(CreateCustomer::class)->handle(test()->owner, ['name' => 'Pak Joko']);

        return null;
    }, 10_000, 'Pak Joko tidak punya utang.'],
    'Midtrans not configured' => [function () {
        config(['services.midtrans.server_key' => '']);

        return null;
    }, 10_000, 'QRIS belum aktif: isi MIDTRANS_SERVER_KEY di file .env.'],
]);

it('cancels the payment and shows the Midtrans message when the charge is refused', function () {
    fakeMidtransForPayments(charge: 'conflict');

    $this->actingAs($this->cashier)
        ->post(route('pos.payments.qris.store'), ['customer_id' => $this->customer->id, 'amount' => 40_000])
        ->assertSessionHasErrors(['payment' => 'Midtrans menolak permintaan: Duplicate order ID. order_id has already been utilized previously.']);

    expect(CustomerPayment::query()->sole())
        ->status->toBe(CustomerPaymentStatus::Cancelled)
        ->number->toBe('PAY-20261125-0001');
    expect($this->customer->fresh()->balance)->toBe(100_000);
});

it('takes the debt down, oldest kasbon first, when polling sees the settlement', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    pollPayment($payment)->assertOk()->assertJson(['status' => 'pending_payment']);
    expect($this->customer->fresh()->balance)->toBe(100_000);

    fakeMidtransForPayments('settlement');
    pollPayment($payment)->assertOk()->assertJson(['status' => 'completed', 'number' => 'PAY-20261125-0001']);

    $payment->refresh();
    expect($payment->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($payment->completed_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($this->customer->fresh()->balance)->toBe(60_000)
        ->and(owedPerKasbon())->toBe([
            $this->sales[0]->id => 0,
            $this->sales[1]->id => 10_000,
            $this->sales[2]->id => 50_000,
        ])
        ->and($payment->entries()->pluck('type')->all())->toBe([LedgerEntryType::Payment, LedgerEntryType::Payment]);

    // Polling again (or a late webhook) books nothing twice.
    pollPayment($payment)->assertJson(['status' => 'completed']);
    expect(app(QrisCustomerPayments::class)->settle($payment))->toBe(QrisCustomerPayments::UNCHANGED)
        ->and(CustomerLedgerEntry::query()->where('type', LedgerEntryType::Payment)->count())->toBe(2);

    expect(Artisan::call('receivables:rebuild'))->toBe(0);
});

it('settles from the webhook once, and logs what it did', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(100_000);

    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'pending'))->assertOk()->assertJson(['result' => 'unchanged']);
    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'settlement'))->assertOk()->assertJson(['result' => 'settled']);
    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'settlement'))->assertOk()->assertJson(['result' => 'unchanged']);

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($this->customer->fresh()->balance)->toBe(0)
        ->and($payment->entries()->count())->toBe(3)
        ->and(PaymentNotification::query()->pluck('result')->all())->toBe(['unchanged', 'settled', 'unchanged'])
        ->and(PaymentNotification::query()->first()->order_id)->toBe('PAY-20261125-0001');
});

it('ignores webhook calls that do not match a waiting payment', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'settlement', gross: '100000.00'))
        ->assertOk()->assertJson(['message' => 'Amount does not match, ignored.']);

    $unknown = paymentNotification($payment, 'settlement');
    $unknown['order_id'] = 'PAY-20261125-0099';
    $unknown['signature_key'] = hash('sha512', 'PAY-20261125-0099200'.$unknown['gross_amount'].PAY_TEST_SERVER_KEY);
    $this->postJson(route('webhooks.midtrans'), $unknown)->assertOk()->assertJson(['message' => 'Unknown order, ignored.']);

    $forged = paymentNotification($payment, 'settlement');
    $forged['signature_key'] = str_repeat('0', 128);
    $this->postJson(route('webhooks.midtrans'), $forged)->assertForbidden();

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::PendingPayment)
        ->and($this->customer->fresh()->balance)->toBe(100_000)
        ->and(PaymentNotification::query()->pluck('result')->all())->toBe(['amount_mismatch', 'unknown_order', 'invalid_signature']);
});

it('lets nothing else take the debt down while a QR waits', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);
    app(OpenShift::class)->handle($this->other, 0);
    $message = 'Masih ada QRIS pelunasan PAY-20261125-0001 untuk Bu Sari yang menunggu pembayaran. Selesaikan atau batalkan dulu.';

    $refusals = [
        'second QR' => fn () => app(StartQrisCustomerPayment::class)->handle($this->other, $this->customer, 10_000),
        'cash payment' => fn () => app(ReceiveCustomerPayment::class)->handle($this->other, $this->customer, 10_000),
        'write-off' => fn () => app(WriteOffCustomerDebt::class)->handle($this->owner, $this->customer, 'Pindah kota'),
        'kasbon return' => fn () => app(ProcessSaleReturn::class)->handle($this->other, $this->sales[0], [$this->sales[0]->items()->value('id') => 1], '1234'),
    ];
    foreach ($refusals as $name => $act) {
        try {
            $act();
            $this->fail("{$name} should have been refused");
        } catch (ValidationException $e) {
            expect(collect($e->errors())->flatten()->all())->toContain($message);
        }
    }

    expect(CustomerPayment::query()->count())->toBe(1)
        ->and($this->customer->fresh()->balance)->toBe(100_000)
        ->and($payment->fresh()->status)->toBe(CustomerPaymentStatus::PendingPayment);

    // New kasbon raises the debt, which is fine, and other customers are unaffected.
    app(CompleteSale::class)->handle($this->other, [['product_unit_id' => $this->gulaPcs->id, 'qty' => 1]], paidAmount: 0, customer: $this->customer);
    expect($this->customer->fresh()->balance)->toBe(110_000);

    $cashSale = app(CompleteSale::class)->handle($this->other, [['product_unit_id' => $this->gulaPcs->id, 'qty' => 1]], paidAmount: 10_000);
    app(ProcessSaleReturn::class)->handle($this->other, $cashSale, [$cashSale->items()->value('id') => 1], '1234');
});

it('clears a QR that has run out before refusing because of it', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    Carbon::setTestNow('2026-11-25 09:20:00'); // past the 09:15 expiry; nobody polled
    fakeMidtransForPayments('expire');

    $cash = app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000);

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Cancelled)
        ->and($cash->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($this->customer->fresh()->balance)->toBe(90_000);
});

it('keeps refusing while a QR that has not run out still waits', function () {
    fakeMidtransForPayments();
    startPaymentQris(40_000);

    Carbon::setTestNow('2026-11-25 09:10:00');
    expect(fn () => app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000))
        ->toThrow(ValidationException::class);
    Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/status'));
});

it('cancels when the cashier gives up, unless the customer did pay', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    $this->actingAs($this->cashier)->post(route('pos.payments.qris.cancel', $payment))
        ->assertRedirect(route('pos.payments.create', ['pelanggan' => $this->customer->id]));

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Cancelled)
        ->and($this->customer->fresh()->balance)->toBe(100_000);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/PAY-20261125-0001/cancel'));

    $paid = startPaymentQris(30_000);
    fakeMidtransForPayments('settlement');
    $this->actingAs($this->cashier)->post(route('pos.payments.qris.cancel', $paid));

    expect($paid->fresh()->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($this->customer->fresh()->balance)->toBe(70_000);
});

it('only lets the cashier who made the QR poll or cancel it', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    $this->actingAs($this->other)->getJson(route('pos.payments.qris.show', $payment))->assertForbidden();
    $this->actingAs($this->other)->post(route('pos.payments.qris.cancel', $payment))->assertForbidden();

    $cash = app(ReceiveCustomerPayment::class);
    $payment->forceFill(['status' => CustomerPaymentStatus::Cancelled])->save();
    $cashPayment = $cash->handle($this->cashier, $this->customer, 5_000);
    $this->actingAs($this->cashier)->getJson(route('pos.payments.qris.show', $cashPayment))->assertNotFound();
});

it('cancels an expired QR by polling, although Midtrans answers 407', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    fakeMidtransForPayments('expire');
    pollPayment($payment)->assertJson(['status' => 'cancelled']);

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Cancelled);

    // An order Midtrans does not know is "no news", not a cancellation.
    $other = startPaymentQris(10_000);
    fakeMidtransForPayments('missing');
    pollPayment($other)->assertJson(['status' => 'pending_payment']);
});

it('books a payment that arrives after it was cancelled, while it still fits the debt', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);
    app(QrisCustomerPayments::class)->cancel($payment);

    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'settlement'))->assertJson(['result' => 'settled']);

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($this->customer->fresh()->balance)->toBe(60_000);
});

it('leaves a late payment cancelled when the debt was paid off meanwhile', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);
    app(QrisCustomerPayments::class)->cancel($payment);
    app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 80_000);

    $this->postJson(route('webhooks.midtrans'), paymentNotification($payment, 'settlement'))->assertJson(['result' => 'over_balance']);

    expect($payment->fresh()->status)->toBe(CustomerPaymentStatus::Cancelled)
        ->and($payment->entries()->count())->toBe(0)
        ->and($this->customer->fresh()->balance)->toBe(20_000);
});

it('keeps QRIS payments out of the drawer but shows them on the shift', function () {
    fakeMidtransForPayments();
    app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000);
    $qris = startPaymentQris(25_000);
    expect($this->shift->fresh()->expectedCash())->toBe(60_000); // a waiting QR counts nowhere

    app(QrisCustomerPayments::class)->settle($qris);
    $cancelled = startPaymentQris(5_000);
    app(QrisCustomerPayments::class)->cancel($cancelled);

    $shift = $this->shift->fresh();
    expect($shift->expectedCash())->toBe(60_000)
        ->and($shift->cashPaymentsTotal())->toBe(10_000)
        ->and($shift->qrisPaymentsTotal())->toBe(25_000);

    $this->actingAs($this->cashier)->get(route('pos.payments.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('shift.paymentsCount', 1)
            ->where('shift.paymentsTotal', 10_000)
            ->where('shift.qrisPaymentsCount', 1)
            ->where('shift.qrisPaymentsTotal', 25_000)
            ->where('shift.expectedCash', 60_000));

    $recap = ShiftRecap::build(DateRange::of('2026-11-20', '2026-11-25'));
    expect($recap['shifts'][0])->toMatchArray(['payments' => 10_000, 'qrisPayments' => 25_000, 'expectedCash' => 60_000])
        ->and($recap['cashiers'][0])->toMatchArray(['payments' => 10_000, 'qrisPayments' => 25_000]);

    $sheet = ReportTables::shifts(DateRange::of('2026-11-20', '2026-11-25'))->sheets()[1];
    $labels = array_column($sheet['columns'], 'label');
    expect($sheet['rows'][0][array_search('Pelunasan tunai', $labels)])->toBe(10_000)
        ->and($sheet['rows'][0][array_search('Pelunasan QRIS', $labels)])->toBe(25_000)
        ->and($sheet['rows'][0][array_search('Kas seharusnya', $labels)])->toBe(60_000);

    $close = app(CloseShift::class)->handle($this->shift->fresh(), 60_000);
    expect($close->expected_cash)->toBe(60_000)->and($close->cash_difference)->toBe(0);
});

it('will not let the cashier close a shift with a QRIS payment waiting', function () {
    fakeMidtransForPayments();
    startPaymentQris(40_000);

    expect(fn () => app(CloseShift::class)->handle($this->shift->fresh(), 50_000))
        ->toThrow(ValidationException::class, 'Masih ada QRIS pelunasan yang menunggu pembayaran. Selesaikan atau batalkan dulu.');
});

it('settles or cancels waiting QRIS payments when an owner closes the shift', function () {
    fakeMidtransForPayments();
    $paid = startPaymentQris(40_000);

    fakeMidtransForPayments('settlement');
    $closed = app(CloseShiftForUser::class)->handle($this->owner, $this->shift->fresh(), 50_000, 'Kasir pulang');

    expect($paid->fresh()->status)->toBe(CustomerPaymentStatus::Completed)
        ->and($this->customer->fresh()->balance)->toBe(60_000)
        ->and($closed->expected_cash)->toBe(50_000);

    // A second shift whose QR was never paid, and Midtrans unreachable: cancelled locally.
    $shift = app(OpenShift::class)->handle($this->cashier, 0);
    fakeMidtransForPayments();
    $unpaid = startPaymentQris(10_000);
    fakeMidtransForPayments('down');
    app(CloseShiftForUser::class)->handle($this->owner, $shift, 0, 'Kasir pulang');

    expect($unpaid->fresh()->status)->toBe(CustomerPaymentStatus::Cancelled)
        ->and($this->customer->fresh()->balance)->toBe(60_000);
});

it('lists waiting QRIS payments on the owner close page', function () {
    fakeMidtransForPayments();
    startPaymentQris(40_000);

    $this->actingAs($this->owner)->get(route('reports.shifts.close.edit', $this->shift))
        ->assertInertia(fn (Assert $page) => $page
            ->has('pendingPayments', 1)
            ->where('pendingPayments.0.number', 'PAY-20261125-0001')
            ->where('pendingPayments.0.customer', 'Bu Sari')
            ->where('pendingPayments.0.amount', 40_000));
});

it('prints the way it was paid and the Midtrans reference on the receipt', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    $this->actingAs($this->cashier)->get(route('pos.payments.receipt', $payment))->assertNotFound();

    app(QrisCustomerPayments::class)->settle($payment);
    $this->actingAs($this->cashier)->get(route('pos.payments.receipt', $payment))
        ->assertOk()
        ->assertSeeInOrder(['PAY-20261125-0001', 'QRIS', 'trx-pay-1']);

    $cash = app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 1_000);
    $this->actingAs($this->cashier)->get(route('pos.payments.receipt', $cash))
        ->assertOk()->assertSee('Tunai')->assertDontSee('trx-pay-1');
});

it('shows the waiting QR on the payments page and the customer card', function () {
    fakeMidtransForPayments();
    $payment = startPaymentQris(40_000);

    $this->actingAs($this->cashier)->get(route('pos.payments.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPayment.id', $payment->id)
            ->where('pendingPayment.number', 'PAY-20261125-0001')
            ->where('pendingPayment.amount', 40_000)
            ->where('pendingPayment.customer', 'Bu Sari')
            ->where('pendingPayment.qrUrl', 'https://api.sandbox.midtrans.com/v2/qris/trx-pay-1/qr-code')
            ->where('debtors.0.waitingQris', 'PAY-20261125-0001'));

    // Another cashier does not get someone else's QR, but sees the customer is held.
    app(OpenShift::class)->handle($this->other, 0);
    $this->actingAs($this->other)->get(route('pos.payments.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingPayment', null)
            ->where('debtors.0.waitingQris', 'PAY-20261125-0001'));

    $this->actingAs($this->owner)->get(route('customers.show', $this->customer))
        ->assertInertia(fn (Assert $page) => $page->where('waitingQris.number', 'PAY-20261125-0001')->where('waitingQris.amount', 40_000));

    app(QrisCustomerPayments::class)->settle($payment);
    $this->actingAs($this->owner)->get(route('customers.show', $this->customer))
        ->assertInertia(fn (Assert $page) => $page
            ->where('waitingQris', null)
            ->where('entries.0.document.number', 'PAY-20261125-0001')
            ->where('entries.0.document.method', 'qris'));
});

it('counts only payments received as the last payment on the receivables report', function () {
    fakeMidtransForPayments();
    Carbon::setTestNow('2026-11-24 09:00:00');
    app(ReceiveCustomerPayment::class)->handle($this->cashier, $this->customer, 10_000);
    Carbon::setTestNow('2026-11-25 09:00:00');
    startPaymentQris(20_000);

    $row = Receivables::build()['rows'][0];
    expect($row['lastPaymentAt'])->toBe(Carbon::parse('2026-11-24 09:00:00', 'Asia/Jakarta')->toIso8601String());
});
