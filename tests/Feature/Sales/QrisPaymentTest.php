<?php

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\OpenShift;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\PaymentNotification;
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
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

const TEST_SERVER_KEY = 'SB-Mid-server-TESTONLY';

beforeEach(function () {
    Carbon::setTestNow('2026-11-22 10:00:00');
    config([
        'services.midtrans.server_key' => TEST_SERVER_KEY,
        'services.midtrans.is_production' => false,
        'pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true],
    ]);
    $this->seed(RoleSeeder::class);

    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->product = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($this->product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($this->product, 10, '3000');
    $this->shift = app(OpenShift::class)->handle($this->cashier, 50000);
    $this->midtransFaked = false;
    $this->midtransStatus = 'pending';
});

/**
 * Fake Midtrans once; later calls only change what the status endpoint answers, because
 * Http::fake stubs added later never override earlier ones.
 */
function fakeMidtrans(string $statusAfter = 'pending'): void
{
    test()->midtransStatus = $statusAfter;

    if (test()->midtransFaked) {
        return;
    }
    test()->midtransFaked = true;

    Http::fake(function (HttpRequest $request) {
        $url = $request->url();

        if (str_ends_with($url, '/v2/charge')) {
            return Http::response([
                'status_code' => '201',
                'status_message' => 'QRIS transaction is created',
                'transaction_id' => 'trx-123',
                'order_id' => $request['transaction_details']['order_id'],
                'gross_amount' => $request['transaction_details']['gross_amount'].'.00',
                'transaction_status' => 'pending',
                'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/trx-123/qr-code']],
                'qr_string' => '00020101021226...',
                'expiry_time' => '2026-11-22 10:15:00',
            ], 201);
        }

        if (str_ends_with($url, '/status')) {
            $status = test()->midtransStatus;

            return Http::response([
                'status_code' => $status === 'settlement' ? '200' : '201',
                'transaction_status' => $status,
            ]);
        }

        if (str_ends_with($url, '/cancel')) {
            return Http::response(['status_code' => '200', 'transaction_status' => 'cancel']);
        }

        return Http::response([], 404);
    });
}

function startQris(): Sale
{
    test()->actingAs(test()->cashier)
        ->post(route('pos.qris.store'), ['items' => [['product_unit_id' => test()->unit->id, 'qty' => 3]]])
        ->assertRedirect(route('pos.index'))
        ->assertSessionHasNoErrors();

    return Sale::query()->latest('id')->firstOrFail();
}

/** @return array<string, string> */
function notification(Sale $sale, string $status, ?string $gross = null, ?string $key = null): array
{
    $gross ??= $sale->total.'.00';
    $statusCode = $status === 'settlement' ? '200' : '202';

    return [
        'order_id' => $sale->number,
        'transaction_id' => 'trx-123',
        'transaction_status' => $status,
        'status_code' => $statusCode,
        'gross_amount' => $gross,
        'payment_type' => 'qris',
        'signature_key' => hash('sha512', $sale->number.$statusCode.$gross.($key ?? TEST_SERVER_KEY)),
    ];
}

it('creates a pending QRIS sale with the QR and moves no stock', function () {
    fakeMidtrans();
    $sale = startQris();

    expect($sale)
        ->status->toBe(SaleStatus::PendingPayment)
        ->payment_method->toBe(PaymentMethod::Qris)
        ->number->toBe('INV-20261122-0001')
        ->total->toBe(12000)
        ->payment_reference->toBe('trx-123')
        ->qr_url->toBe('https://api.sandbox.midtrans.com/v2/qris/trx-123/qr-code')
        ->and($sale->payment_expires_at->format('Y-m-d H:i'))->toBe('2026-11-22 10:15')
        ->and($sale->items)->toHaveCount(1)
        ->and($this->product->fresh()->stock_on_hand)->toBe(10)
        ->and(StockMovement::where('type', StockMovementType::Sale)->count())->toBe(0);

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.sandbox.midtrans.com/v2/charge'
        && $r['payment_type'] === 'qris'
        && $r['transaction_details'] === ['order_id' => 'INV-20261122-0001', 'gross_amount' => 12000]
        && $r->hasHeader('Authorization', 'Basic '.base64_encode(TEST_SERVER_KEY.':')));

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('qrisEnabled', true)
            ->where('pendingQris.number', 'INV-20261122-0001')
            ->where('pendingQris.total', 12000));
});

it('settles on a signed webhook exactly once, however often Midtrans repeats it', function () {
    fakeMidtrans();
    $sale = startQris();

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement'))->assertOk()->assertJson(['result' => 'settled']);
    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement'))->assertOk()->assertJson(['result' => 'unchanged']);
    // A late "pending" or "expire" after settlement must not undo it either.
    $this->postJson(route('webhooks.midtrans'), notification($sale, 'expire'))->assertOk()->assertJson(['result' => 'unchanged']);

    $sale->refresh();
    expect($sale)
        ->status->toBe(SaleStatus::Completed)
        ->paid_amount->toBe(12000)
        ->change_amount->toBe(0)
        ->cogs_total->toBe('9000.0000')
        ->and($this->product->fresh()->stock_on_hand)->toBe(7)
        ->and(StockMovement::where('type', StockMovementType::Sale)->count())->toBe(1)
        ->and(PaymentNotification::count())->toBe(3);

    // QRIS money is not in the drawer.
    expect($this->shift->fresh()->expectedCash())->toBe(50000);
});

it('rejects unsigned or tampered notifications and logs them', function () {
    fakeMidtrans();
    $sale = startQris();

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', key: 'attacker-guess'))->assertForbidden();

    $tampered = notification($sale, 'settlement');
    $tampered['transaction_status'] = 'settlement';
    $tampered['gross_amount'] = '1.00'; // signature was for the real amount
    $this->postJson(route('webhooks.midtrans'), $tampered)->assertForbidden();

    expect($sale->fresh()->status)->toBe(SaleStatus::PendingPayment)
        ->and($this->product->fresh()->stock_on_hand)->toBe(10)
        ->and(PaymentNotification::where('signature_valid', false)->count())->toBe(2);
});

it('verifies notifications with the Signature Secret Key once one is configured', function () {
    config(['services.midtrans.signature_key' => 'SIGNATURE-SECRET-TESTONLY']);
    fakeMidtrans();
    $sale = startQris();

    // Signed with the server key: no longer accepted once the secret differs.
    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement'))->assertForbidden();
    expect($sale->fresh()->status)->toBe(SaleStatus::PendingPayment);

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', key: 'SIGNATURE-SECRET-TESTONLY'))
        ->assertOk()->assertJson(['result' => 'settled']);
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed);
});

it('still accepts the previous Signature Secret Key during a rotation', function () {
    config([
        'services.midtrans.signature_key' => 'NEW-SECRET-TESTONLY',
        'services.midtrans.signature_key_previous' => 'OLD-SECRET-TESTONLY',
    ]);
    fakeMidtrans();
    $sale = startQris();

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', key: 'OLD-SECRET-TESTONLY'))
        ->assertOk()->assertJson(['result' => 'settled']);
});

it('ignores a correctly signed notification for the wrong amount or an unknown order', function () {
    fakeMidtrans();
    $sale = startQris();

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', gross: '1000.00'))
        ->assertOk()->assertJson(['message' => 'Amount does not match, ignored.']);

    $ghost = notification($sale, 'settlement');
    $ghost['order_id'] = 'INV-19990101-0001';
    $ghost['signature_key'] = hash('sha512', 'INV-19990101-0001'.'200'.$ghost['gross_amount'].TEST_SERVER_KEY);
    $this->postJson(route('webhooks.midtrans'), $ghost)->assertOk()->assertJson(['message' => 'Unknown order, ignored.']);

    expect($sale->fresh()->status)->toBe(SaleStatus::PendingPayment);
});

it('cancels on expiry, and still books a payment that arrives just after', function () {
    fakeMidtrans();
    $sale = startQris();

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'expire'))->assertOk()->assertJson(['result' => 'cancelled']);
    expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
        ->and($this->product->fresh()->stock_on_hand)->toBe(10);

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement'))->assertOk()->assertJson(['result' => 'settled']);
    expect($sale->fresh()->status)->toBe(SaleStatus::Completed)
        ->and($this->product->fresh()->stock_on_hand)->toBe(7);
});

it('learns about the payment by polling Midtrans when no webhook arrives', function () {
    fakeMidtrans('pending');
    $sale = startQris();

    $this->actingAs($this->cashier)->getJson(route('pos.qris.show', $sale))
        ->assertOk()->assertJson(['status' => 'pending_payment']);

    fakeMidtrans('settlement');
    Carbon::setTestNow(now()->addSeconds(5));

    $this->actingAs($this->cashier)->getJson(route('pos.qris.show', $sale))
        ->assertOk()->assertJson(['status' => 'completed'])
        ->assertSessionHas('last_sale_id', $sale->id);

    expect($this->product->fresh()->stock_on_hand)->toBe(7);

    // The till shows "Transaksi berhasil" once, not on every later visit.
    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('lastSale.number', $sale->number));
    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('lastSale', null));
});

it('throttles live status checks while polling', function () {
    fakeMidtrans('pending');
    $sale = startQris();

    $this->actingAs($this->cashier)->getJson(route('pos.qris.show', $sale));
    $this->actingAs($this->cashier)->getJson(route('pos.qris.show', $sale));

    Http::assertSentCount(2); // the charge, then one status check
});

it('lets the cashier cancel, unless the customer has already paid', function () {
    fakeMidtrans('pending');
    $sale = startQris();

    $this->actingAs($this->cashier)->post(route('pos.qris.cancel', $sale))->assertRedirect(route('pos.index'));
    expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/INV-20261122-0001/cancel'));

    Carbon::setTestNow(now()->addMinute());
    fakeMidtrans('settlement');
    $paid = startQris();
    $this->actingAs($this->cashier)->post(route('pos.qris.cancel', $paid))->assertSessionHas('last_sale_id', $paid->id);
    expect($paid->fresh()->status)->toBe(SaleStatus::Completed);
});

it('keeps the shift open while a QRIS is pending, and allows only one at a time', function () {
    fakeMidtrans();
    startQris();

    $this->actingAs($this->cashier)
        ->post(route('pos.qris.store'), ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]]])
        ->assertSessionHasErrors('payment');

    expect(fn () => app(CloseShift::class)->handle($this->shift, 50000))
        ->toThrow(ValidationException::class, 'QRIS yang menunggu');
});

it('refuses QRIS for goods that are already out of stock', function () {
    fakeMidtrans();

    $this->actingAs($this->cashier)
        ->post(route('pos.qris.store'), ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 11]]])
        ->assertSessionHasErrors('items');

    Http::assertNothingSent();
    expect(Sale::count())->toBe(0);
});

it('cancels the sale if Midtrans refuses the charge', function () {
    Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '401', 'status_message' => 'Unknown Merchant server_key/id'], 401)]);

    $this->actingAs($this->cashier)
        ->post(route('pos.qris.store'), ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]]])
        ->assertSessionHasErrors(['payment' => 'Midtrans menolak permintaan: Unknown Merchant server_key/id']);

    expect(Sale::sole()->status)->toBe(SaleStatus::Cancelled);
});

it('stays switched off until the server key is configured', function () {
    config(['services.midtrans.server_key' => '']);
    Http::fake();

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('qrisEnabled', false));

    $this->actingAs($this->cashier)
        ->post(route('pos.qris.store'), ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]]])
        ->assertSessionHasErrors('payment');

    $this->postJson(route('webhooks.midtrans'), ['order_id' => 'x', 'signature_key' => 'y'])->assertForbidden();
    Http::assertNothingSent();
});

it('keeps other cashiers away from someone else’s QR', function () {
    fakeMidtrans();
    $sale = startQris();
    $other = User::factory()->create()->assignRole(Role::Cashier->value);

    $this->actingAs($other)->getJson(route('pos.qris.show', $sale))->assertForbidden();
    $this->actingAs($other)->post(route('pos.qris.cancel', $sale))->assertForbidden();
});

// --- Mixed cash + QRIS (DESIGN.md §11) -------------------------------------------------------

function startSplit(int $cash): Sale
{
    test()->actingAs(test()->cashier)
        ->post(route('pos.qris.store'), [
            'items' => [['product_unit_id' => test()->unit->id, 'qty' => 3]],
            'cash_amount' => $cash,
        ])
        ->assertRedirect(route('pos.index'))
        ->assertSessionHasNoErrors();

    return Sale::query()->latest('id')->firstOrFail();
}

it('starts a mixed payment: cash part stored, Midtrans charged only the QRIS part, no stock moved', function () {
    fakeMidtrans();
    $sale = startSplit(5000);

    expect($sale)
        ->status->toBe(SaleStatus::PendingPayment)
        ->payment_method->toBe(PaymentMethod::Split)
        ->total->toBe(12000)
        ->split_cash_amount->toBe(5000)
        ->and($sale->qrisPart())->toBe(7000)
        ->and($this->product->fresh()->stock_on_hand)->toBe(10);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/charge')
        && $r['transaction_details'] === ['order_id' => 'INV-20261122-0001', 'gross_amount' => 7000]);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingQris.total', 12000)
            ->where('pendingQris.amount', 7000)
            ->where('pendingQris.cashAmount', 5000));
});

it('refuses a mixed payment whose cash part is zero, the whole total or more', function (int $cash) {
    fakeMidtrans();

    $this->actingAs($this->cashier)
        ->post(route('pos.qris.store'), [
            'items' => [['product_unit_id' => $this->unit->id, 'qty' => 3]],
            'cash_amount' => $cash,
        ])
        ->assertSessionHasErrors('cash_amount');

    expect(Sale::count())->toBe(0);
    Http::assertNothingSent();
})->with(['zero' => 0, 'whole total' => 12000, 'more than total' => 20000]);

it('settles a mixed payment once by webhook for the QRIS part, not the total', function () {
    fakeMidtrans();
    $sale = startSplit(5000);

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', gross: '12000.00'))
        ->assertOk()->assertJson(['message' => 'Amount does not match, ignored.']);
    expect($sale->fresh()->status)->toBe(SaleStatus::PendingPayment);

    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', gross: '7000.00'))
        ->assertOk()->assertJson(['result' => 'settled']);
    $this->postJson(route('webhooks.midtrans'), notification($sale, 'settlement', gross: '7000.00'))
        ->assertOk()->assertJson(['result' => 'unchanged']);

    expect($sale->fresh())
        ->status->toBe(SaleStatus::Completed)
        ->paid_amount->toBe(12000)
        ->change_amount->toBe(0)
        ->and($this->product->fresh()->stock_on_hand)->toBe(7)
        ->and(StockMovement::where('type', StockMovementType::Sale)->count())->toBe(1);
});

it('settles a mixed payment by polling and shows both parts on the till, receipt and history', function () {
    fakeMidtrans('settlement');
    $sale = startSplit(5000);
    Carbon::setTestNow(now()->addSeconds(5));

    $this->actingAs($this->cashier)->getJson(route('pos.qris.show', $sale))
        ->assertOk()->assertJson(['status' => 'completed']);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lastSale.method', 'split')
            ->where('lastSale.cashAmount', 5000)
            ->where('lastSale.qrisAmount', 7000));

    $this->actingAs($this->cashier)->get(route('pos.receipts.show', $sale))
        ->assertOk()
        ->assertSeeInOrder(['Tunai', '5.000', 'QRIS', '7.000']);

    $this->actingAs($this->cashier)->get(route('pos.sales.show', $sale))
        ->assertInertia(fn (Assert $page) => $page
            ->where('sale.paymentMethod', 'split')
            ->where('sale.cashAmount', 5000)
            ->where('sale.qrisAmount', 7000));

    $this->actingAs($this->cashier)->get(route('pos.sales.index', ['method' => 'split']))
        ->assertInertia(fn (Assert $page) => $page->has('sales.data', 1));
});

it('counts only the cash part of completed mixed payments in the drawer', function () {
    fakeMidtrans();
    $paid = startSplit(5000);
    $this->postJson(route('webhooks.midtrans'), notification($paid, 'settlement', gross: '7000.00'))->assertOk();

    $expired = startSplit(4000);
    $this->postJson(route('webhooks.midtrans'), notification($expired, 'expire', gross: '8000.00'))
        ->assertOk()->assertJson(['result' => 'cancelled']);

    // A plain cash sale for comparison: 1 × 4000.
    $this->actingAs($this->cashier)
        ->post(route('pos.sales.store'), ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]], 'paid_amount' => 5000])
        ->assertSessionHasNoErrors();

    $shift = $this->shift->fresh();
    expect($shift->cashSalesTotal())->toBe(4000 + 5000)
        ->and($shift->expectedCash())->toBe(50000 + 9000);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('shift.salesCount', 2));

    $closed = app(CloseShift::class)->handle($shift, 59000);
    expect($closed->cash_difference)->toBe(0);

    $recap = ShiftRecap::build(DateRange::of('2026-11-22', '2026-11-22'));
    expect($recap['shifts'][0])
        ->cashSales->toBe(9000)
        ->qrisSales->toBe(7000)
        ->transactions->toBe(2);

    $summary = SalesSummary::build(DateRange::of('2026-11-22', '2026-11-22'));
    expect($summary['totals'])
        ->gross->toBe(16000)
        ->cash->toBe(9000)
        ->qris->toBe(7000);
});

it('reminds the cashier to hand the cash back when a mixed payment is cancelled', function () {
    fakeMidtrans('pending');
    $sale = startSplit(5000);

    $this->actingAs($this->cashier)->post(route('pos.qris.cancel', $sale))
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'QRIS INV-20261122-0001 dibatalkan. Kembalikan uang tunai Rp 5.000 ke pelanggan.');

    expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
        ->and($this->shift->fresh()->expectedCash())->toBe(50000);
});
