<?php

use App\Enums\CustomerPaymentStatus;
use App\Enums\PaymentMethod;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockDisposal;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Reports\Expiry;
use App\Reports\Receivables;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

it('seeds demo accounts with roles and an opening stock that matches the ledger', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'owner@kasir.test')->sole()->hasRole(Role::Owner->value))->toBeTrue()
        ->and(User::where('email', 'gudang@kasir.test')->sole()->hasRole(Role::Warehouse->value))->toBeTrue()
        ->and(User::where('email', 'kasir@kasir.test')->sole()->hasRole(Role::Cashier->value))->toBeTrue()
        ->and(Hash::check(DemoUserSeeder::DEMO_OWNER_PIN, (string) User::where('email', 'owner@kasir.test')->value('pin_hash')))->toBeTrue()
        ->and(User::where('email', 'kasir@kasir.test')->value('pin_hash'))->toBeNull();

    expect(GoodsReceipt::count())->toBe(1);

    // Indomie Goreng: 5 dus × 40 = 200 pcs, at 99.000 / 40 = 2.475 per pcs
    expect(Product::where('sku', 'MIE-001')->sole())
        ->stock_on_hand->toBe(200)
        ->avg_cost->toBe('2475.0000');

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('seeds sales history outside local only when pos.demo_sales_history is on', function () {
    $this->seed(DatabaseSeeder::class);
    expect(Sale::query()->exists())->toBeFalse();

    config(['pos.demo_sales_history' => true]);
    $this->seed(DatabaseSeeder::class);

    expect(Sale::query()->count())->toBeGreaterThan(300)
        ->and(GoodsReceipt::count())->toBeGreaterThan(1);
    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('can be re-run without duplicating stock', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(GoodsReceipt::count())->toBe(1)
        ->and(Product::where('sku', 'MIE-001')->sole()->stock_on_hand)->toBe(200);
});

it('seeds purchase orders in every state through the real actions', function () {
    config(['pos.demo_sales_history' => true]);
    $this->seed(DatabaseSeeder::class);

    $statuses = PurchaseOrder::query()->pluck('status')->map->value->countBy();

    expect($statuses->get('received'))->toBeGreaterThan(5)
        ->and($statuses->get('partial'))->toBe(1)
        ->and($statuses->get('ordered'))->toBe(2)
        ->and($statuses->get('draft'))->toBe(1)
        ->and(PurchaseOrder::query()->get()->filter->isLate()->count())->toBe(1)
        ->and(GoodsReceipt::query()->whereNull('purchase_order_id')->count())->toBeGreaterThan(1);

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('seeds kasbon, pelunasan and a kasbon return through the real actions', function () {
    config(['pos.demo_sales_history' => true]);
    $this->seed(DatabaseSeeder::class);

    expect(Customer::query()->count())->toBe(5)
        ->and(Sale::query()->where('payment_method', PaymentMethod::Credit)->count())->toBeGreaterThan(5)
        ->and(CustomerPayment::query()->count())->toBeGreaterThan(2)
        ->and(SaleReturn::query()->where('credit_amount', '>', 0)->count())->toBeGreaterThanOrEqual(1);

    $report = Receivables::build();
    expect($report['totals']['balance'])->toBeGreaterThan(0)
        ->and($report['totals']['overdue'])->toBeGreaterThan(0)
        ->and(collect($report['rows'])->firstWhere('customer', 'Pak Darto')['age31to60'] ?? 0)->toBeGreaterThan(0);

    $this->artisan('stock:rebuild')->assertSuccessful();
    $this->artisan('receivables:rebuild')->assertSuccessful();
});

it('seeds settled QRIS pelunasan through the real actions, without calling Midtrans', function () {
    config(['pos.demo_sales_history' => true, 'services.midtrans.server_key' => 'real-key-kept']);
    Http::preventStrayRequests();
    $this->seed(DatabaseSeeder::class);

    $qris = CustomerPayment::query()->where('method', CustomerPayment::QRIS)->get();
    expect($qris)->not->toBeEmpty()
        ->and($qris->every(fn (CustomerPayment $p) => $p->status === CustomerPaymentStatus::Completed && $p->entries()->exists()))->toBeTrue()
        ->and(CustomerPayment::query()->where('method', CustomerPayment::CASH)->count())->toBeGreaterThan(0)
        ->and(config('services.midtrans.server_key'))->toBe('real-key-kept');

    $this->artisan('receivables:rebuild')->assertSuccessful();
});

it('seeds products that track expiry, with expired, expiring and undisposed batches, through the real actions', function () {
    config(['pos.demo_sales_history' => true, 'pos.expiry.warn_days' => 30]);
    $this->seed(DatabaseSeeder::class);

    expect(Product::query()->where('tracks_expiry', true)->pluck('sku')->sort()->values()->all())->toBe(['MNM-002', 'ROT-001', 'SUS-001'])
        ->and(StockDisposal::query()->count())->toBeGreaterThanOrEqual(1)
        ->and(StockOpname::query()->whereHas('items.batches')->count())->toBe(1)
        ->and(StockMovement::query()->where('type', StockMovementType::Sale)->whereNotNull('stock_batch_id')->count())->toBeGreaterThan(20);

    $report = Expiry::build();
    expect($report['totals']['expired']['count'])->toBeGreaterThanOrEqual(1)
        ->and(collect($report['rows'])->contains(fn (array $r) => $r['sku'] === 'SUS-001' && $r['days'] !== null && $r['days'] <= 7 && $r['days'] >= 0))->toBeTrue();

    $this->artisan('stock:rebuild')->assertSuccessful();
});
