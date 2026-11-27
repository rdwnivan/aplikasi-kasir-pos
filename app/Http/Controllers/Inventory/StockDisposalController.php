<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\DisposeStock;
use App\Http\Controllers\Controller;
use App\Models\StockBatch;
use App\Models\StockDisposal;
use App\Models\StockDisposalItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Buang barang: expired goods out of their batches (DESIGN.md §19 P6c). Owner and warehouse. */
class StockDisposalController extends Controller
{
    public function index(): Response
    {
        $disposals = StockDisposal::query()
            ->with('user')
            ->withCount('items')
            ->latest('id')
            ->paginate(25)
            ->through(fn (StockDisposal $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'createdAt' => $d->created_at?->toIso8601String(),
                'user' => $d->user->name,
                'reason' => $d->reason,
                'itemsCount' => $d->items_count,
                'totalValue' => $d->total_value,
            ]);

        return Inertia::render('inventory/stock-disposals/index', ['disposals' => $disposals]);
    }

    public function create(Request $request): Response
    {
        $batches = StockBatch::query()
            ->with('product.baseUnit')
            ->where('qty_on_hand', '>', 0)
            ->whereHas('product', fn ($q) => $q->where('tracks_expiry', true))
            ->fefo()
            ->get()
            ->sortBy(fn (StockBatch $b) => [$b->expires_on?->toDateString() ?? '0000', $b->product->name])
            ->values();

        // ?kedaluwarsa=1 starts with every expired batch, ?batch={id} with one batch.
        $lines = $batches
            ->filter(fn (StockBatch $b) => $request->boolean('kedaluwarsa') ? $b->isExpired() : $b->id === $request->integer('batch'))
            ->map(fn (StockBatch $b) => ['batchId' => $b->id, 'qty' => $b->qty_on_hand])
            ->values()
            ->all();

        return Inertia::render('inventory/stock-disposals/create', [
            'batches' => $batches->map(fn (StockBatch $b) => [
                'id' => $b->id,
                'productId' => $b->product_id,
                'product' => $b->product->name,
                'sku' => $b->product->sku,
                'baseUnit' => $b->product->baseUnit->name,
                'label' => $b->label(),
                'expiresOn' => $b->expires_on?->toDateString(),
                'status' => $b->status(),
                'qty' => $b->qty_on_hand,
                'avgCost' => (float) $b->product->avg_cost,
            ])->all(),
            'lines' => $lines,
        ]);
    }

    public function store(Request $request, DisposeStock $dispose): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.stock_batch_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:100000000'],
        ], attributes: ['reason' => 'alasan', 'items' => 'batch', 'items.*.qty' => 'jumlah']);

        $disposal = $dispose->handle(
            $request->user(),
            array_values(array_map(fn (array $i) => ['stock_batch_id' => (int) $i['stock_batch_id'], 'qty' => (int) $i['qty']], $data['items'])),
            $data['reason'],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$disposal->number} disimpan. Stok sudah berkurang."]);

        return to_route('stock-disposals.show', $disposal);
    }

    public function show(StockDisposal $stockDisposal): Response
    {
        $stockDisposal->load(['user', 'items' => fn ($q) => $q->with(['batch', 'product.baseUnit'])->orderBy('id')]);

        return Inertia::render('inventory/stock-disposals/show', [
            'disposal' => [
                'id' => $stockDisposal->id,
                'number' => $stockDisposal->number,
                'createdAt' => $stockDisposal->created_at?->toIso8601String(),
                'user' => $stockDisposal->user->name,
                'reason' => $stockDisposal->reason,
                'totalValue' => $stockDisposal->total_value,
                'items' => $stockDisposal->items->map(fn (StockDisposalItem $i) => [
                    'id' => $i->id,
                    'productId' => $i->product_id,
                    'product' => $i->product->name,
                    'sku' => $i->product->sku,
                    'baseUnit' => $i->product->baseUnit->name,
                    'batch' => $i->batch->label(),
                    'qty' => $i->qty,
                    'unitCost' => (float) $i->unit_cost,
                    'value' => $i->value,
                ])->all(),
            ],
        ]);
    }
}
