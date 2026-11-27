<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Actions\Sales\QrisPayments;
use App\Actions\Sales\StartQrisPayment;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\ExpiredStockException;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Selling, settling and returning goods that track expiry: first expired, first out (DESIGN.md §19).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    config([
        'pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true],
        'pos.credit.default_limit' => 500_000,
        'services.midtrans.server_key' => 'SB-Mid-server-TESTONLY',
        'services.midtrans.is_production' => false,
    ]);
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['pin_hash' => Hash::make('1234')])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->susu = Product::factory()->create(['name' => 'Susu UHT', 'base_unit_id' => $pcs->id]);
    $this->susuPcs = ProductUnit::factory()->for($this->susu)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 6000]);

    $this->ledger = app(StockLedger::class);
    $this->ledger->startTracking($this->susu);
    $this->ledger->receive($this->susu, 8, '5000', batches: [
        ['expires_on' => '2026-12-10', 'qty' => 5],
        ['expires_on' => '2026-12-01', 'qty' => 3],
    ]);
    $this->early = StockBatch::query()->whereDate('expires_on', '2026-12-01')->sole();
    $this->late = StockBatch::query()->whereDate('expires_on', '2026-12-10')->sole();

    $this->shift = app(OpenShift::class)->handle($this->cashier, 100_000);
});

function sellSusu(object $test, int $qty, ?int $paid = null): Sale
{
    return app(CompleteSale::class)->handle($test->cashier, [['product_unit_id' => $test->susuPcs->id, 'qty' => $qty]], paidAmount: $paid ?? $qty * 6000);
}

function fakeCharge(): void
{
    Http::fake(fn (HttpRequest $request) => Http::response([
        'status_code' => '201',
        'transaction_id' => 'trx-batch',
        'order_id' => $request['transaction_details']['order_id'],
        'transaction_status' => 'pending',
        'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://example.test/qr']],
        'qr_string' => '000201',
    ], 201));
}

it('takes a cash sale from the earliest batch first, one movement per batch, at the average HPP', function () {
    $sale = sellSusu($this, 4);

    expect($this->early->fresh()->qty_on_hand)->toBe(0)
        ->and($this->late->fresh()->qty_on_hand)->toBe(4)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->pluck('qty', 'stock_batch_id')->all())
        ->toBe([$this->early->id => -3, $this->late->id => -1])
        ->and($sale->items->sole()->cogs)->toBe('20000.0000')
        ->and($sale->fresh()->cogs_total)->toBe('20000.0000');
});

it('takes kasbon from the earliest batch too', function () {
    $customer = app(CreateCustomer::class)->handle($this->cashier, ['name' => 'Bu Sari', 'phone' => '081200000001']);
    app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->susuPcs->id, 'qty' => 2]], paidAmount: 0, customer: $customer);

    expect($this->early->fresh()->qty_on_hand)->toBe(1);
});

it('refuses to sell expired goods, writing nothing', function () {
    Carbon::setTestNow('2026-12-02 09:00:00');

    expect(fn () => sellSusu($this, 6))->toThrow(ExpiredStockException::class, 'Stok Susu UHT yang belum kedaluwarsa tinggal 5 (3 sudah kedaluwarsa).')
        ->and(Sale::query()->count())->toBe(0)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->count())->toBe(0);

    sellSusu($this, 5);
    expect($this->late->fresh()->qty_on_hand)->toBe(0)->and($this->early->fresh()->qty_on_hand)->toBe(3);
});

it('tells the till why, and shows only stock that may be sold', function () {
    Carbon::setTestNow('2026-12-02 09:00:00');

    $this->actingAs($this->cashier)->post(route('pos.sales.store'), [
        'items' => [['product_unit_id' => $this->susuPcs->id, 'qty' => 6]],
        'paid_amount' => 36_000,
    ])->assertSessionHasErrors(['items' => 'Stok Susu UHT yang belum kedaluwarsa tinggal 5 (3 sudah kedaluwarsa).']);

    $this->actingAs($this->cashier)->get(route('pos.index'))
        ->assertInertia(fn (Assert $page) => $page->where('catalog.0.stock', 5));
});

it('will not start a QR for goods that have expired', function () {
    fakeCharge();
    Carbon::setTestNow('2026-12-02 09:00:00');

    expect(fn () => app(StartQrisPayment::class)->handle($this->cashier, [['product_unit_id' => $this->susuPcs->id, 'qty' => 6]]))
        ->toThrow(ValidationException::class, 'Stok Susu UHT tidak cukup (tersisa 5).');
});

it('settles a QR paid just after midnight from the batches that could be sold when it was made', function () {
    fakeCharge();
    Carbon::setTestNow('2026-12-01 23:55:00');
    $sale = app(StartQrisPayment::class)->handle($this->cashier, [['product_unit_id' => $this->susuPcs->id, 'qty' => 7]]);

    Carbon::setTestNow('2026-12-02 00:05:00'); // the 1 Dec batch has now expired
    expect(app(QrisPayments::class)->settle($sale))->toBe(QrisPayments::SETTLED)
        ->and($sale->fresh()->status)->toBe(SaleStatus::Completed)
        ->and($this->early->fresh()->qty_on_hand)->toBe(0)
        ->and($this->late->fresh()->qty_on_hand)->toBe(1);
});

describe('returns', function () {
    it('puts goods back in the batches the sale took, the earliest first', function () {
        $sale = sellSusu($this, 4); // 3 from 1 Dec, 1 from 10 Dec
        $item = $sale->items->sole();

        app(ProcessSaleReturn::class)->handle($this->cashier, $sale, [$item->id => 2], '1234');
        expect($this->early->fresh()->qty_on_hand)->toBe(2)->and($this->late->fresh()->qty_on_hand)->toBe(4);

        app(ProcessSaleReturn::class)->handle($this->cashier, $sale, [$item->id => 2], '1234');
        expect($this->early->fresh()->qty_on_hand)->toBe(3)->and($this->late->fresh()->qty_on_hand)->toBe(5);

        $this->artisan('stock:rebuild')->assertSuccessful();
    });

    it('returns into a batch that has expired since, so it shows up to be thrown away', function () {
        $sale = sellSusu($this, 2);
        Carbon::setTestNow('2026-12-05 09:00:00');

        app(ProcessSaleReturn::class)->handle($this->cashier, $sale, [$sale->items->sole()->id => 2], '1234');

        expect($this->early->fresh()->qty_on_hand)->toBe(3)
            ->and($this->ledger->sellable($this->susu->fresh()))->toBe(5);
    });

    it('returns goods sold before tracking began into the batch without a date', function () {
        $pcs = Unit::query()->where('name', 'pcs')->sole();
        $roti = Product::factory()->create(['name' => 'Roti', 'base_unit_id' => $pcs->id]);
        $rotiPcs = ProductUnit::factory()->for($roti)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 15_000]);
        $this->ledger->receive($roti, 4, '12000');
        $sale = app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $rotiPcs->id, 'qty' => 2]], paidAmount: 30_000);
        $this->ledger->startTracking($roti);

        app(ProcessSaleReturn::class)->handle($this->cashier, $sale, [$sale->items->sole()->id => 1], '1234');

        expect(StockBatch::query()->where('product_id', $roti->id)->whereNull('expires_on')->sole()->qty_on_hand)->toBe(3);
        $this->artisan('stock:rebuild')->assertSuccessful();
    });
});
