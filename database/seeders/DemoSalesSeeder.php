<?php

namespace Database\Seeders;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\QrisCustomerPayments;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Customers\StartQrisCustomerPayment;
use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\ProcessSaleReturn;
use App\Enums\DiscountType;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Sales\CartBuilder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Thirty days of shop life for the demo dashboard and reports: a cashier shift every day,
 * 10–30 sales (busier at weekends), restocks when shelves run low (at slightly drifting
 * buy prices, so the moving-average HPP moves; every other restock goes through a purchase
 * order placed two days earlier), and the odd return. It ends with purchase orders in every
 * open state: one partly received, one on its way, one late, and a draft. Everything goes through
 * the real actions, so the ledger, HPP and drawer figures are all consistent.
 *
 * Runs only in the local environment or with pos.demo_sales_history (see DatabaseSeeder), and only on a database without
 * sales. Deterministic: the same seed gives the same history.
 */
class DemoSalesSeeder extends Seeder
{
    private const DAYS = 30;

    private int $restocks = 0;

    /** @var array<string, Customer> Regulars who buy on kasbon (DESIGN.md §17). */
    private array $customers = [];

    /**
     * Five regulars, added by the owner before the history starts. Pak Darto takes kasbon
     * ten days before that, in a short shift of its own, and never pays: the overdue debt.
     *
     * @param  Collection<int, ProductUnit>  $units
     */
    private function seedCustomers(User $owner, User $cashier, $units, CarbonImmutable $until): void
    {
        Carbon::setTestNow($until->subDays(self::DAYS + 12)->setTime(9, 0));

        foreach ([
            'sari' => ['name' => 'Bu Sari (warung sebelah)', 'phone' => '081234500001', 'credit_limit' => 1_000_000, 'address' => 'Gg. Melati No. 3'],
            'darto' => ['name' => 'Pak Darto', 'phone' => '081234500002', 'credit_limit' => 500_000, 'note' => 'Tukang ojek pangkalan depan'],
            'rina' => ['name' => 'Mbak Rina', 'phone' => '081234500003', 'credit_limit' => 750_000],
            'yanto' => ['name' => 'Pak RT Yanto', 'phone' => '081234500004', 'credit_limit' => 1_500_000, 'address' => 'Jl. Kenanga No. 1'],
            'dewi' => ['name' => 'Bu Dewi', 'credit_limit' => 300_000],
        ] as $key => $data) {
            $this->customers[$key] = app(CreateCustomer::class)->handle($owner, $data);
        }

        $day = $until->subDays(self::DAYS + 10)->setTime(16, 0);
        Carbon::setTestNow($day);
        $shift = app(OpenShift::class)->handle($cashier, 100_000);
        Carbon::setTestNow($day->addMinutes(30));
        $this->kasbon($cashier, $this->customers['darto'], $this->basket($units));
        Carbon::setTestNow($day->addHours(2));
        app(CloseShift::class)->handle($shift, $shift->fresh()?->expectedCash() ?? 0);

        Carbon::setTestNow();
    }

    /** About one sale in 25 goes on kasbon, for one of the regulars (never Pak Darto again). */
    private function kasbonCustomer(): ?Customer
    {
        if (mt_rand(1, 25) !== 1) {
            return null;
        }

        $pool = ['sari', 'sari', 'rina', 'yanto', 'dewi'];

        return $this->customers[$pool[mt_rand(0, count($pool) - 1)]];
    }

    /**
     * Kasbon through the real checkout, now and then with a cash down payment. Returns null
     * when the regular is at their limit, so the sale is rung up as cash instead.
     *
     * @param  list<array{product_unit_id: int, qty: int}>  $lines
     */
    private function kasbon(User $cashier, Customer $customer, array $lines): ?Sale
    {
        $total = app(CartBuilder::class)->build($lines)->priced->total;
        $down = mt_rand(1, 3) === 1 ? intdiv($total, 3) - intdiv($total, 3) % 5_000 : 0;
        $customer->refresh();

        if ($total < 1 || $customer->availableCredit() < $total - $down) {
            return null;
        }

        return app(CompleteSale::class)->handle($cashier, $lines, paidAmount: $down, customer: $customer);
    }

    /** On about one day in four a regular (not Pak Darto) pays half, or all, of what they owe. */
    private function payOffSome(User $cashier, CarbonInterface $at): void
    {
        if (mt_rand(1, 4) !== 1) {
            return;
        }

        $debtors = array_values(array_filter(
            $this->customers,
            fn (Customer $c, string $key) => $key !== 'darto' && $c->refresh()->balance > 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($debtors === []) {
            return;
        }

        $customer = $debtors[mt_rand(0, count($debtors) - 1)];
        $amount = mt_rand(1, 2) === 1 ? $customer->balance : max(1, intdiv($customer->balance, 2) - intdiv($customer->balance, 2) % 5_000);

        Carbon::setTestNow($at);
        $note = $amount === $customer->balance ? 'Lunas' : null;

        // About a third pay by QRIS (DESIGN.md §18), already settled.
        if (mt_rand(1, 3) === 1) {
            $this->payByQris($cashier, $customer, $amount, $note);

            return;
        }

        app(ReceiveCustomerPayment::class)->handle($cashier, $customer, $amount, $note);
    }

    /**
     * A QRIS pelunasan through the real actions: StartQrisCustomerPayment, then settlement.
     * Midtrans's charge endpoint is faked only for this call (seeding never talks to the
     * sandbox), with a stand-in server key; both are put back afterwards.
     */
    private function payByQris(User $cashier, Customer $customer, int $amount, ?string $note): void
    {
        $serverKey = config('services.midtrans.server_key');
        $http = Http::getFacadeRoot();
        config(['services.midtrans.server_key' => 'demo-seeder']);
        // A factory of its own, so the stub never outlives this call or mixes with a test's fakes.
        Http::swap(new HttpFactory(app('events')));
        Http::preventStrayRequests();
        Http::fake(['*/v2/charge' => fn (HttpRequest $request) => Http::response([
            'status_code' => '201',
            'transaction_id' => 'demo-'.$request['transaction_details']['order_id'],
            'transaction_status' => 'pending',
            'actions' => [],
            'qr_string' => null,
            'expiry_time' => now()->addMinutes(15)->setTimezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
        ], 201)]);

        try {
            $payment = app(StartQrisCustomerPayment::class)->handle($cashier, $customer, $amount, $note);
            app(QrisCustomerPayments::class)->settle($payment);
        } finally {
            config(['services.midtrans.server_key' => $serverKey]);
            Http::swap($http);
        }
    }

    /** One unit back from the latest kasbon: it comes off the debt (DESIGN.md §17.2). */
    private function returnKasbon(User $cashier, CarbonInterface $at): void
    {
        $sale = Sale::query()->where('payment_method', PaymentMethod::Credit)->where('customer_id', '!=', $this->customers['darto']->id)->latest('id')->first();
        $item = $sale?->items()->first();

        if ($sale !== null && $item !== null) {
            Carbon::setTestNow($at);
            app(ProcessSaleReturn::class)->handle($cashier, $sale, [$item->id => 1], DemoUserSeeder::DEMO_OWNER_PIN, 'Salah beli');
        }
    }

    public function run(?CarbonImmutable $until = null): void
    {
        if (Sale::query()->exists()) {
            return;
        }

        $until ??= CarbonImmutable::now();
        mt_srand(20261122);

        $cashier = User::query()->where('email', 'kasir@kasir.test')->firstOrFail();
        $receiver = User::query()->where('email', 'gudang@kasir.test')->firstOrFail();
        $supplier = Supplier::query()->first();

        $units = ProductUnit::query()->with('product')->get();
        $buyUnits = GoodsReceiptItem::query()->orderBy('id')->get()->keyBy('product_id');
        $this->seedCustomers(User::query()->where('email', 'owner@kasir.test')->firstOrFail(), $cashier, $units, $until);

        try {
            for ($d = self::DAYS; $d >= 1; $d--) {
                $day = $until->subDays($d)->setTime(8, 0);
                Carbon::setTestNow($day);

                $shift = app(OpenShift::class)->handle($cashier, 200_000);
                $weekend = $day->isWeekend();
                $count = mt_rand(10, 20) + ($weekend ? mt_rand(5, 10) : 0);
                $clock = $day->addMinutes(15);
                $todaysSales = [];

                for ($s = 0; $s < $count; $s++) {
                    $clock = $clock->addMinutes(mt_rand(10, 35));
                    Carbon::setTestNow($clock);

                    $lines = $this->basket($units);
                    $this->restockIfLow($lines, $units, $buyUnits, $receiver, $supplier?->id);
                    Carbon::setTestNow($clock);

                    $discount = mt_rand(1, 10) === 1;

                    // About one sale in 25 goes on the slate.
                    $regular = $this->kasbonCustomer();
                    if ($regular !== null && ($sale = $this->kasbon($cashier, $regular, $lines)) !== null) {
                        $todaysSales[] = $sale;

                        continue;
                    }

                    $sale = app(CompleteSale::class)->handle(
                        $cashier,
                        $lines,
                        paidAmount: 10_000_000,
                        discountType: $discount ? DiscountType::Percent : null,
                        discountValue: $discount ? 5 : 0,
                    );
                    $this->settleLikeACustomer($sale);
                    $todaysSales[] = $sale;
                }

                // Regulars drop by to pay off part or all of their kasbon.
                $this->payOffSome($cashier, $clock->addMinutes(10));

                // Halfway through the month a kasbon customer brings something back.
                if ($d === intdiv(self::DAYS, 2)) {
                    $this->returnKasbon($cashier, $clock->addMinutes(15));
                }

                // Now and then something comes back the same day.
                if (mt_rand(1, 5) === 1) {
                    Carbon::setTestNow($clock->addMinutes(20));
                    $sale = $todaysSales[array_rand($todaysSales)];
                    $item = $sale->items()->first();
                    if ($item !== null) {
                        app(ProcessSaleReturn::class)->handle($cashier, $sale, [$item->id => 1], DemoUserSeeder::DEMO_OWNER_PIN, 'Kemasan rusak');
                    }
                }

                Carbon::setTestNow($clock->addHour());
                $expected = $shift->fresh()?->expectedCash() ?? 0;
                // Most days the drawer is right; sometimes a little short or over.
                $off = mt_rand(1, 6) === 1 ? mt_rand(-5, 2) * 1000 : 0;
                app(CloseShift::class)->handle($shift, max(0, $expected + $off));
            }

            if ($supplier !== null) {
                $this->openPurchaseOrders($until, $buyUnits, $receiver, $supplier->id);
            }
        } finally {
            Carbon::setTestNow();
        }

    }

    /**
     * 1–4 different sellable units; pieces mostly, the odd pack or carton.
     *
     * @param  Collection<int, ProductUnit>  $units
     * @return list<array{product_unit_id: int, qty: int}>
     */
    private function basket($units): array
    {
        $lines = [];
        $wanted = mt_rand(1, 4);

        while (count($lines) < $wanted) {
            /** @var ProductUnit $unit */
            $unit = $units[mt_rand(0, $units->count() - 1)];
            if ($unit->conversion > 1 && mt_rand(1, 4) !== 1) {
                continue; // cartons are the exception
            }
            $lines[$unit->id] = [
                'product_unit_id' => $unit->id,
                'qty' => $unit->conversion > 1 ? 1 : mt_rand(1, 3),
            ];
        }

        return array_values($lines);
    }

    /**
     * Receive goods before a product runs out, at a buy price that drifts a few percent.
     *
     * @param  list<array{product_unit_id: int, qty: int}>  $lines
     * @param  Collection<int, ProductUnit>  $units
     * @param  Collection<int, GoodsReceiptItem>  $buyUnits
     */
    private function restockIfLow(array $lines, $units, $buyUnits, User $receiver, ?int $supplierId): void
    {
        $restock = [];

        foreach ($lines as $line) {
            $unit = $units->firstWhere('id', $line['product_unit_id']);
            $product = Product::query()->find($unit?->product_id);
            $buy = $product ? $buyUnits->get($product->id) : null;

            if ($unit === null || $product === null || $buy === null) {
                continue;
            }

            $needed = $line['qty'] * $unit->conversion + max($product->min_stock, 1);
            if ($product->stock_on_hand >= $needed || isset($restock[$product->id])) {
                continue;
            }

            $perUnit = max(1, $buy->conversion);
            $qty = (int) ceil(max($product->min_stock * 3, $needed) / $perUnit);
            $price = (int) (round($buy->unit_price * (1 + mt_rand(-3, 5) / 100) / 100) * 100);

            $restock[$product->id] = [
                'product_id' => $product->id,
                'unit_id' => $buy->unit_id,
                'qty' => $qty,
                'unit_price' => $price,
            ];
        }

        if ($restock === []) {
            return;
        }

        // No extra random draws here, so the sales history stays the same as before POs existed.
        if ($supplierId !== null && $this->restocks++ % 2 === 0) {
            $arrived = now();
            Carbon::setTestNow($arrived->copy()->subDays(2)->setTime(9, 0));
            $order = $this->placeOrder($receiver, $supplierId, array_values($restock), $arrived->copy()->startOfDay());
            Carbon::setTestNow($arrived);

            app(ReceivePurchaseOrder::class)->handle($order, $receiver, array_values($order->items->map(fn (PurchaseOrderItem $i) => [
                'purchase_order_item_id' => $i->id,
                'qty' => $i->qty,
                'unit_price' => $i->unit_price,
            ])->all()), $arrived, 'Restok');

            return;
        }

        app(ReceiveGoods::class)->handle($receiver, array_values($restock), $supplierId, now(), 'Restok');
    }

    /**
     * The purchasing desk at the end of the demo period: a delivery that came half, one due
     * tomorrow, one overdue, and a draft still being put together.
     *
     * @param  Collection<int, GoodsReceiptItem>  $buyUnits
     */
    private function openPurchaseOrders(CarbonImmutable $until, $buyUnits, User $receiver, int $supplierId): void
    {
        $lines = $buyUnits->values()->map(fn (GoodsReceiptItem $i) => [
            'product_id' => $i->product_id,
            'unit_id' => $i->unit_id,
            'qty' => 4,
            'unit_price' => $i->unit_price,
        ]);
        $pick = fn (int $from, int $count) => array_values($lines->slice($from, $count)->all());

        if ($lines->count() < 10) {
            return;
        }

        Carbon::setTestNow($until->subDays(4)->setTime(10, 0));
        $partial = $this->placeOrder($receiver, $supplierId, $pick(0, 3), $until, 'Order mingguan');
        Carbon::setTestNow($until->subDay()->setTime(21, 0));
        app(ReceivePurchaseOrder::class)->handle($partial, $receiver, array_values($partial->items->map(fn (PurchaseOrderItem $i, int $n) => [
            'purchase_order_item_id' => $i->id,
            'qty' => $n === 0 ? $i->qty : intdiv($i->qty, 2),
            'unit_price' => $i->unit_price,
        ])->all()), now(), 'Kiriman pertama, sisanya menyusul');

        Carbon::setTestNow($until->subDays(5)->setTime(11, 0));
        $this->placeOrder($receiver, $supplierId, $pick(3, 2), $until->subDays(2), 'Belum dikirim supplier');

        Carbon::setTestNow($until->subDay()->setTime(14, 0));
        $this->placeOrder($receiver, $supplierId, $pick(5, 3), $until->addDay());

        Carbon::setTestNow($until->setTime(7, 30));
        app(SavePurchaseOrder::class)->handle(
            $receiver,
            ['supplier_id' => $supplierId, 'expected_at' => null, 'note' => 'Cek harga dulu'],
            $pick(8, 2),
        );
    }

    /** @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $lines */
    private function placeOrder(User $user, int $supplierId, array $lines, CarbonInterface $expectedAt, ?string $note = null): PurchaseOrder
    {
        $draft = app(SavePurchaseOrder::class)->handle(
            $user,
            ['supplier_id' => $supplierId, 'expected_at' => $expectedAt->toDateString(), 'note' => $note],
            $lines,
        );

        return app(OrderPurchaseOrder::class)->handle($draft, $user);
    }

    /**
     * Cash customers hand over a round amount; about a quarter pay by QRIS and one in ten
     * mixes the two (round cash, the rest by QRIS). Demo data only: QRIS and mixed sales here
     * are written as already settled, since no payment gateway is involved.
     */
    private function settleLikeACustomer(Sale $sale): void
    {
        $roll = mt_rand(1, 20);

        if ($roll <= 5) {
            $sale->forceFill(['payment_method' => PaymentMethod::Qris, 'paid_amount' => $sale->total, 'change_amount' => 0])->save();

            return;
        }

        $cashPart = intdiv($sale->total, 10_000) * 10_000;
        if ($roll <= 7 && $cashPart > 0 && $cashPart < $sale->total) {
            $sale->forceFill([
                'payment_method' => PaymentMethod::Split,
                'split_cash_amount' => $cashPart,
                'paid_amount' => $sale->total,
                'change_amount' => 0,
            ])->save();

            return;
        }

        $step = $sale->total > 50_000 ? 50_000 : ($sale->total > 20_000 ? 10_000 : 5_000);
        $paid = (int) (ceil($sale->total / $step) * $step);
        $sale->forceFill(['paid_amount' => $paid, 'change_amount' => $paid - $sale->total])->save();
    }
}
