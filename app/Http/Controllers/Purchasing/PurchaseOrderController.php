<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\ClosePurchaseOrder;
use App\Actions\Purchasing\DeletePurchaseOrder;
use App\Actions\Purchasing\OrderPurchaseOrder;
use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Inventory\ProductOptions;
use App\Http\Requests\Purchasing\PurchaseOrderReceiptRequest;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Queries\OpenPurchaseOrders;
use App\Queries\PurchaseSuggestions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        $status = PurchaseOrderStatus::tryFrom((string) $request->query('status'));
        $supplierId = $request->integer('supplier') ?: null;

        $orders = PurchaseOrder::query()
            ->with(['supplier', 'user', 'items' => fn ($q) => $q->withSum('receiptItems', 'qty')])
            ->when($status, fn (Builder $q, PurchaseOrderStatus $s) => $q->where('status', $s))
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PurchaseOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status->value,
                'supplier' => $o->supplier->name,
                'user' => $o->user->name,
                'createdAt' => $o->created_at?->toIso8601String(),
                'orderedAt' => $o->ordered_at?->toIso8601String(),
                'expectedAt' => $o->expected_at?->toDateString(),
                'isLate' => $o->isLate(),
                'itemsCount' => $o->items->count(),
                'total' => $o->total,
                'receivedValue' => OpenPurchaseOrders::receivedValue($o),
            ]);

        return Inertia::render('purchasing/purchase-orders/index', [
            'orders' => $orders,
            'filters' => ['status' => $status?->value, 'supplier' => $supplierId],
            'statuses' => self::statuses(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, $request->query('dari') === 'stok-minimum' ? PurchaseSuggestions::fromLowStock() : []);
    }

    public function store(PurchaseOrderRequest $request, SavePurchaseOrder $save): RedirectResponse
    {
        $order = $save->handle($request->user(), $request->attributesForOrder(), $request->items());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft pesanan pembelian disimpan.']);

        return to_route('purchase-orders.show', $order);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $order = $purchaseOrder->load([
            'supplier',
            'user',
            'items' => fn ($q) => $q->withSum('receiptItems', 'qty')->with(['product' => fn ($p) => $p->withTrashed(), 'unit']),
            'goodsReceipts' => fn ($q) => $q->with('user')->orderBy('id'),
        ]);
        $status = $order->status;
        $hasReceived = $order->goodsReceipts->isNotEmpty();

        return Inertia::render('purchasing/purchase-orders/show', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $status->value,
                'supplier' => $order->supplier->name,
                'user' => $order->user->name,
                'note' => $order->note,
                'closeReason' => $order->close_reason,
                'createdAt' => $order->created_at?->toIso8601String(),
                'orderedAt' => $order->ordered_at?->toIso8601String(),
                'finishedAt' => $order->finished_at?->toIso8601String(),
                'expectedAt' => $order->expected_at?->toDateString(),
                'isLate' => $order->isLate(),
                'total' => $order->total,
                'receivedValue' => OpenPurchaseOrders::receivedValue($order),
                'items' => $order->items->map(function (PurchaseOrderItem $item) {
                    $received = OpenPurchaseOrders::received($item);

                    return [
                        'id' => $item->id,
                        'productId' => $item->product_id,
                        'product' => $item->product->name,
                        'sku' => $item->product->sku,
                        'unit' => $item->unit->name,
                        'qty' => $item->qty,
                        'received' => $received,
                        'remaining' => max(0, $item->qty - $received),
                        'unitPrice' => $item->unit_price,
                        'subtotal' => $item->subtotal,
                    ];
                })->all(),
                'receipts' => $order->goodsReceipts->map(fn (GoodsReceipt $r) => [
                    'id' => $r->id,
                    'number' => $r->number,
                    'receivedAt' => $r->received_at->toDateString(),
                    'user' => $r->user->name,
                    'total' => $r->total,
                ])->all(),
            ],
            'statuses' => self::statuses(),
            'can' => [
                'edit' => $status === PurchaseOrderStatus::Draft,
                'order' => $status === PurchaseOrderStatus::Draft,
                'receive' => $status->isOpen(),
                'close' => $status === PurchaseOrderStatus::Partial && $hasReceived,
                'cancel' => $status === PurchaseOrderStatus::Ordered && ! $hasReceived,
            ],
        ]);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::Draft) {
            return to_route('purchase-orders.show', $purchaseOrder);
        }

        return $this->form($purchaseOrder, []);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, SavePurchaseOrder $save): RedirectResponse
    {
        $save->handle($request->user(), $request->attributesForOrder(), $request->items(), $purchaseOrder);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft pesanan pembelian diperbarui.']);

        return to_route('purchase-orders.show', $purchaseOrder);
    }

    public function destroy(PurchaseOrder $purchaseOrder, DeletePurchaseOrder $delete): RedirectResponse
    {
        $delete->handle($purchaseOrder);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft pesanan pembelian dihapus.']);

        return to_route('purchase-orders.index');
    }

    public function order(Request $request, PurchaseOrder $purchaseOrder, OrderPurchaseOrder $place): RedirectResponse
    {
        $order = $place->handle($purchaseOrder, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pesanan {$order->number} dibuat. Barangnya bisa diterima saat datang."]);

        return to_route('purchase-orders.show', $order);
    }

    public function receive(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if (! $purchaseOrder->status->isOpen()) {
            return to_route('purchase-orders.show', $purchaseOrder);
        }

        $order = $purchaseOrder->load([
            'supplier',
            'items' => fn ($q) => $q->withSum('receiptItems', 'qty')->with(['product.baseUnit', 'product.units', 'unit']),
        ]);

        return Inertia::render('purchasing/purchase-orders/receive', [
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'supplier' => $order->supplier->name,
                'items' => $order->items->map(function (PurchaseOrderItem $item) {
                    $received = OpenPurchaseOrders::received($item);

                    return [
                        'id' => $item->id,
                        'product' => $item->product->name,
                        'sku' => $item->product->sku,
                        'tracksExpiry' => $item->product->tracks_expiry,
                        'unit' => $item->unit->name,
                        'baseUnit' => $item->product->baseUnit->name,
                        'conversion' => $item->product->units->firstWhere('unit_id', $item->unit_id)->conversion ?? 1,
                        'qty' => $item->qty,
                        'received' => $received,
                        'remaining' => max(0, $item->qty - $received),
                        'unitPrice' => $item->unit_price,
                    ];
                })->all(),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function storeReceipt(PurchaseOrderReceiptRequest $request, PurchaseOrder $purchaseOrder, ReceivePurchaseOrder $receive): RedirectResponse
    {
        $receipt = $receive->handle(
            $purchaseOrder,
            $request->user(),
            $request->items(),
            $request->receivedAt(),
            $request->validated('note'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Penerimaan {$receipt->number} disimpan. Stok sudah bertambah."]);

        return to_route('goods-receipts.show', $receipt);
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder, ClosePurchaseOrder $close): RedirectResponse
    {
        $order = $close->handle($purchaseOrder, $request->user(), self::reason($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sisa pesanan {$order->number} ditutup."]);

        return to_route('purchase-orders.show', $order);
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $cancel): RedirectResponse
    {
        $order = $cancel->handle($purchaseOrder, $request->user(), self::reason($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pesanan {$order->number} dibatalkan."]);

        return to_route('purchase-orders.show', $order);
    }

    /** @param  list<array{product_id: int, unit_id: int, qty: int, unit_price: int}>  $suggested */
    private function form(?PurchaseOrder $order, array $suggested): Response
    {
        $order?->load('items');

        return Inertia::render('purchasing/purchase-orders/form', [
            'order' => $order === null ? null : [
                'id' => $order->id,
                'supplierId' => $order->supplier_id,
                'expectedAt' => $order->expected_at?->toDateString(),
                'note' => $order->note,
                'items' => $order->items->map(fn (PurchaseOrderItem $i) => [
                    'product_id' => $i->product_id,
                    'unit_id' => $i->unit_id,
                    'qty' => $i->qty,
                    'unit_price' => $i->unit_price,
                ])->all(),
            ],
            'suggested' => $suggested,
            'products' => ProductOptions::active(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'lastPrices' => (object) PurchaseSuggestions::lastPrices(),
            'today' => now()->toDateString(),
        ]);
    }

    private static function reason(Request $request): string
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], attributes: ['reason' => 'alasan']);

        return $data['reason'];
    }

    /** @return list<array{value: string, label: string}> */
    private static function statuses(): array
    {
        return array_map(
            fn (PurchaseOrderStatus $s) => ['value' => $s->value, 'label' => $s->label()],
            PurchaseOrderStatus::cases(),
        );
    }
}
