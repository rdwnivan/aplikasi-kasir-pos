<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ReceiveGoods;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\GoodsReceiptRequest;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
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

        $receipts = GoodsReceipt::query()
            ->with(['supplier', 'user'])
            ->withCount('items')
            ->whereBetween('received_at', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($supplierId, fn (Builder $q, int $id) => $q->where('supplier_id', $id))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'receivedAt' => $r->received_at->toDateString(),
                'supplier' => $r->supplier?->name,
                'user' => $r->user->name,
                'itemsCount' => $r->items_count,
                'total' => $r->total,
            ]);

        return Inertia::render('inventory/goods-receipts/index', [
            'receipts' => $receipts,
            'filters' => [...$range->toArray(), 'supplier' => $supplierId],
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
        $goodsReceipt->load(['supplier', 'user', 'items' => fn ($q) => $q->with(['product' => fn ($p) => $p->withTrashed()->with('baseUnit'), 'unit'])]);

        return Inertia::render('inventory/goods-receipts/show', [
            'receipt' => [
                'id' => $goodsReceipt->id,
                'number' => $goodsReceipt->number,
                'receivedAt' => $goodsReceipt->received_at->toDateString(),
                'createdAt' => $goodsReceipt->created_at?->toIso8601String(),
                'supplier' => $goodsReceipt->supplier?->name,
                'user' => $goodsReceipt->user->name,
                'note' => $goodsReceipt->note,
                'total' => $goodsReceipt->total,
                'items' => $goodsReceipt->items->map(fn (GoodsReceiptItem $item) => [
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
                ])->all(),
            ],
        ]);
    }
}
