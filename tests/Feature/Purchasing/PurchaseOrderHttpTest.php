<?php

use App\Actions\Inventory\ReceiveGoods;
use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Warehouse->value);
    $this->supplier = Supplier::factory()->create(['name' => 'CV Sumber Rejeki']);

    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->dus = Unit::factory()->create(['name' => 'dus']);
    $this->product = Product::factory()->create(['name' => 'Indomie Goreng', 'base_unit_id' => $this->pcs->id, 'min_stock' => 50]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 3500]);
    ProductUnit::factory()->for($this->product)->create(['unit_id' => $this->dus->id, 'conversion' => 40, 'price' => 125000]);

    $this->line = ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 5, 'unit_price' => 112000];
    $this->placeOrder = function (array $lines = []) {
        $po = app(SavePurchaseOrder::class)->handle(
            $this->staff,
            ['supplier_id' => $this->supplier->id, 'expected_at' => '2026-11-25', 'note' => null],
            $lines ?: [$this->line],
        );

        return app(OrderPurchaseOrder::class)->handle($po, $this->staff);
    };
});

it('keeps cashiers out', function () {
    $cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $this->actingAs($cashier)->get(route('purchase-orders.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('purchase-orders.create'))->assertForbidden();
    $this->actingAs($cashier)->post(route('purchase-orders.store'), [])->assertForbidden();
});

it('creates a draft, places it and shows it', function () {
    $this->actingAs($this->staff)->post(route('purchase-orders.store'), [
        'supplier_id' => $this->supplier->id,
        'expected_at' => '2026-11-25',
        'note' => 'Lewat WhatsApp',
        'items' => [$this->line],
    ])->assertRedirect(route('purchase-orders.show', $po = PurchaseOrder::sole()));

    expect($po->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($po->user_id)->toBe($this->staff->id);

    $this->actingAs($this->staff)->post(route('purchase-orders.order', $po))
        ->assertRedirect(route('purchase-orders.show', $po));

    $this->actingAs($this->staff)->get(route('purchase-orders.show', $po))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/show')
            ->where('order.number', 'PO-20261123-0001')
            ->where('order.status', 'ordered')
            ->where('order.supplier', 'CV Sumber Rejeki')
            ->where('order.total', 560000)
            ->where('order.items.0.product', 'Indomie Goreng')
            ->where('order.items.0.unit', 'dus')
            ->where('order.items.0.qty', 5)
            ->where('order.items.0.received', 0)
            ->where('order.items.0.remaining', 5)
            ->where('can.receive', true)
            ->where('can.cancel', true)
            ->where('can.close', false)
            ->where('can.edit', false));
});

it('validates the draft form', function () {
    $this->actingAs($this->staff)->post(route('purchase-orders.store'), [
        'supplier_id' => null,
        'expected_at' => '2026-11-01',
        'items' => [],
    ])->assertSessionHasErrors(['supplier_id', 'expected_at', 'items']);

    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('edits and deletes a draft', function () {
    $po = app(SavePurchaseOrder::class)->handle(
        $this->staff,
        ['supplier_id' => $this->supplier->id, 'expected_at' => null, 'note' => null],
        [$this->line],
    );

    $this->actingAs($this->staff)->get(route('purchase-orders.edit', $po))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/form')
            ->where('order.id', $po->id)
            ->where('order.items.0.qty', 5));

    $this->actingAs($this->staff)->put(route('purchase-orders.update', $po), [
        'supplier_id' => $this->supplier->id,
        'items' => [[...$this->line, 'qty' => 7]],
    ])->assertRedirect(route('purchase-orders.show', $po));
    expect($po->fresh()->total)->toBe(7 * 112000);

    $this->actingAs($this->staff)->delete(route('purchase-orders.destroy', $po))
        ->assertRedirect(route('purchase-orders.index'));
    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('prefills the receive form with what is still outstanding and receives it', function () {
    $po = ($this->placeOrder)();
    $item = $po->items->first();
    app(ReceivePurchaseOrder::class)->handle($po, $this->staff, [
        ['purchase_order_item_id' => $item->id, 'qty' => 2, 'unit_price' => 112000],
    ]);

    $this->actingAs($this->staff)->get(route('purchase-orders.receive', $po))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/receive')
            ->where('order.items.0.id', $item->id)
            ->where('order.items.0.remaining', 3)
            ->where('order.items.0.unitPrice', 112000)
            ->where('today', '2026-11-23'));

    $this->actingAs($this->staff)->post(route('purchase-orders.receive.store', $po), [
        'received_at' => '2026-11-23',
        'note' => 'Faktur 77',
        'items' => [['purchase_order_item_id' => $item->id, 'qty' => 3, 'unit_price' => 113000]],
    ])->assertRedirect(route('goods-receipts.show', $receipt = GoodsReceipt::query()->latest('id')->first()));

    expect($receipt->purchase_order_id)->toBe($po->id)
        ->and($receipt->note)->toBe('Faktur 77')
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Received)
        ->and($this->product->fresh()->stock_on_hand)->toBe(200);

    $this->actingAs($this->staff)->get(route('goods-receipts.show', $receipt))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.purchaseOrder.id', $po->id)
            ->where('receipt.purchaseOrder.number', 'PO-20261123-0001'));
});

it('shows over-receipt as a field error', function () {
    $po = ($this->placeOrder)();

    $this->actingAs($this->staff)->post(route('purchase-orders.receive.store', $po), [
        'received_at' => '2026-11-23',
        'items' => [['purchase_order_item_id' => $po->items->first()->id, 'qty' => 6, 'unit_price' => 112000]],
    ])->assertSessionHasErrors('items.0.qty');

    expect(GoodsReceipt::query()->count())->toBe(0);
});

it('closes and cancels orders with a reason', function () {
    $partial = ($this->placeOrder)();
    app(ReceivePurchaseOrder::class)->handle($partial, $this->staff, [
        ['purchase_order_item_id' => $partial->items->first()->id, 'qty' => 1, 'unit_price' => 112000],
    ]);
    $this->actingAs($this->staff)->post(route('purchase-orders.close', $partial), ['reason' => 'Sisa kosong'])
        ->assertRedirect(route('purchase-orders.show', $partial));
    expect($partial->fresh()->status)->toBe(PurchaseOrderStatus::Closed);

    $open = ($this->placeOrder)();
    $this->actingAs($this->staff)->post(route('purchase-orders.cancel', $open), ['reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingAs($this->staff)->post(route('purchase-orders.cancel', $open), ['reason' => 'Batal order'])
        ->assertRedirect(route('purchase-orders.show', $open));
    expect($open->fresh()->status)->toBe(PurchaseOrderStatus::Cancelled);
});

it('lists orders with progress, flags late ones and filters by status', function () {
    $late = ($this->placeOrder)(); // expected 25 Nov
    $partial = ($this->placeOrder)();
    app(ReceivePurchaseOrder::class)->handle($partial, $this->staff, [
        ['purchase_order_item_id' => $partial->items->first()->id, 'qty' => 2, 'unit_price' => 112000],
    ]);
    app(SavePurchaseOrder::class)->handle(
        $this->staff,
        ['supplier_id' => $this->supplier->id, 'expected_at' => null, 'note' => null],
        [$this->line],
    );

    Carbon::setTestNow('2026-11-26 08:00:00');

    $this->actingAs($this->staff)->get(route('purchase-orders.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/index')
            ->has('orders.data', 3)
            ->where('orders.data.0.status', 'draft')
            ->where('orders.data.0.number', null)
            ->where('orders.data.1.id', $partial->id)
            ->where('orders.data.1.receivedValue', 224000)
            ->where('orders.data.1.isLate', true)
            ->where('orders.data.2.id', $late->id)
            ->where('orders.data.2.receivedValue', 0));

    $this->actingAs($this->staff)->get(route('purchase-orders.index', ['status' => 'partial']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('orders.data', 1)
            ->where('orders.data.0.id', $partial->id)
            ->where('filters.status', 'partial'));
});

it('suggests a draft from low stock: last purchase unit and price, enough for twice the minimum', function () {
    // 10 pcs left, minimum 50: needs 90 pcs to reach 100, i.e. 3 dus (120 pcs).
    app(ReceiveGoods::class)->handle($this->staff, [
        ['product_id' => $this->product->id, 'unit_id' => $this->dus->id, 'qty' => 1, 'unit_price' => 114000],
    ]);
    app(StockLedger::class)->adjustTo($this->product, 10, StockMovementType::Opname);

    // Never bought, out of stock, minimum 0: one base unit at no known price.
    $soap = Product::factory()->create(['name' => 'Sabun Lifebuoy', 'base_unit_id' => $this->pcs->id, 'min_stock' => 0]);
    ProductUnit::factory()->for($soap)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 5000]);

    // Comfortably stocked: not suggested.
    $water = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $this->pcs->id, 'min_stock' => 5]);
    ProductUnit::factory()->for($water)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($water, 50, '3000');

    $this->actingAs($this->staff)->get(route('purchase-orders.create', ['dari' => 'stok-minimum']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('purchasing/purchase-orders/form')
            ->where('order', null)
            ->has('suggested', 2)
            ->where('suggested.0.product_id', $this->product->id)
            ->where('suggested.0.unit_id', $this->dus->id)
            ->where('suggested.0.qty', 3)
            ->where('suggested.0.unit_price', 114000)
            ->where('suggested.1.product_id', $soap->id)
            ->where('suggested.1.unit_id', $this->pcs->id)
            ->where('suggested.1.qty', 1)
            ->where('suggested.1.unit_price', 0)
            ->where("lastPrices.{$this->product->id}-{$this->dus->id}", 114000));

    $this->actingAs($this->staff)->get(route('purchase-orders.create'))
        ->assertInertia(fn (Assert $page) => $page->where('suggested', []));
});

it('refuses to delete a supplier that has purchase orders', function () {
    ($this->placeOrder)();
    $owner = User::factory()->create()->assignRole(Role::Owner->value);

    $this->actingAs($owner)->delete(route('suppliers.destroy', $this->supplier))
        ->assertSessionHasErrors('supplier');

    expect($this->supplier->fresh())->not->toBeNull();
});

it('shows open orders on the stock dashboard', function () {
    $po = ($this->placeOrder)(); // 5 dus × 112.000, expected 25 Nov
    app(ReceivePurchaseOrder::class)->handle($po, $this->staff, [
        ['purchase_order_item_id' => $po->items->first()->id, 'qty' => 2, 'unit_price' => 112000],
    ]);
    ($this->placeOrder)();

    Carbon::setTestNow('2026-11-26 08:00:00');

    $this->actingAs($this->staff)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('openOrders.count', 2)
            ->where('openOrders.late', 2)
            ->where('openOrders.outstandingValue', 3 * 112000 + 5 * 112000));
});
