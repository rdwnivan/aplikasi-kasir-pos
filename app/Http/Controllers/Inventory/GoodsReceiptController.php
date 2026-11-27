<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ReceiveGoods;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\GoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoodsReceiptController extends Controller
{
    public function index(Request $request): Response
    {
        $range = DateRange::fromRequest($request, defaultDays: 30);
        $supplierId = $request->integer('supplier') ?: null;
        $source = in_array($request->query('asal'), ['po', 'langsung'], true) ? (string) $request->query('asal') : null;

        $receipts = GoodsReceipt::query()
            ->with(['supplier', 'user', 'purchaseOrder'])
            ->withCount('items')
            ->whereBetween('received_at', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->when($source === 'po', fn (Builder $q) => $q->whereNotNull('purchase_order_id'))
            ->when($source === 'langsung', fn (Builder $q) => $q->whereNull('purchase_order_id'))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'receivedAt' => $r->received_at->toDateString(),
                'supplier' => $r->supplier?->name,
                'purchaseOrder' => $r->purchaseOrder?->number,
                'user' => $r->user->name,
                'itemsCount' => $r->items_count,
                'total' => $r->total,
            ]);

        return Inertia::render('inventory/goods-receipts/index', [
            'receipts' => $receipts,
            'filters' => [...$range->toArray(), 'supplier' => $supplierId, 'source' => $source],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('inventory/goods-receipts/create', [
            'products' => ProductOptions::active(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(GoodsReceiptRequest $request, ReceiveGoods $receive): RedirectResponse
    {
        $receipt = $receive->handle(
            $request->user(),
            $request->items(),
            $request->validated('supplier_id') !== null ? (int) $request->validated('supplier_id') : null,
            $request->receivedAt(),
            $request->validated('note'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Penerimaan {$receipt->number} disimpan. Stok sudah bertambah."]);

        return to_route('goods-receipts.show', $receipt);
    }

    public function show(GoodsReceipt $goodsReceipt): Response
    {
        $goodsReceipt->load(['supplier', 'user', 'purchaseOrder', 'items' => fn ($q) => $q->with(['product' => fn ($p) => $p->withTrashed()->with('baseUnit'), 'unit'])]);

        // Batches come from the receipt's own movements (one source, DESIGN.md §19 P4), per
        // product in base units, shown once on the product's first line.
        $batches = StockMovement::query()
            ->with('batch')
            ->where('reference_type', $goodsReceipt->getMorphClass())
            ->where('reference_id', $goodsReceipt->id)
            ->whereNotNull('stock_batch_id')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');
        $shown = [];

        return Inertia::render('inventory/goods-receipts/show', [
            'receipt' => [
                'id' => $goodsReceipt->id,
                'number' => $goodsReceipt->number,
                'receivedAt' => $goodsReceipt->received_at->toDateString(),
                'createdAt' => $goodsReceipt->created_at?->toIso8601String(),
                'supplier' => $goodsReceipt->supplier?->name,
                'purchaseOrder' => $goodsReceipt->purchaseOrder === null ? null : [
                    'id' => $goodsReceipt->purchaseOrder->id,
                    'number' => $goodsReceipt->purchaseOrder->number,
                ],
                'user' => $goodsReceipt->user->name,
                'note' => $goodsReceipt->note,
                'total' => $goodsReceipt->total,
                'items' => $goodsReceipt->items->map(function (GoodsReceiptItem $item) use ($batches, &$shown) {
                    $first = ! isset($shown[$item->product_id]);
                    $shown[$item->product_id] = true;

                    return [
                        'tracksExpiry' => isset($batches[$item->product_id]),
                        'batches' => $first && isset($batches[$item->product_id])
                            ? $batches[$item->product_id]->map(fn (StockMovement $m) => [
                                'id' => $m->stock_batch_id,
                                'expiresOn' => $m->batch?->expires_on?->toDateString(),
                                'code' => $m->batch?->code,
                                'qty' => $m->qty,
                            ])->values()->all()
                            : [],
                        'id' => $item->id,
                        'productId' => $item->product_id,
                        'product' => $item->product->name,
                        'sku' => $item->product->sku,
                        'unit' => $item->unit->name,
                        'baseUnit' => $item->product->baseUnit->name,
                        'qty' => $item->qty,
                        'conversion' => $item->conversion,
                        'unitPrice' => $item->unit_price,
                        'subtotal' => $item->subtotal,
                    ];
                })->all(),
            ],
        ]);
    }
}
