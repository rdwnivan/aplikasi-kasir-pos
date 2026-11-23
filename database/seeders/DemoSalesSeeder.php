<?php

namespace Database\Seeders;

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
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
