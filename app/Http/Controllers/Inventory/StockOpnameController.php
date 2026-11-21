<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateStockOpname;
use App\Actions\Inventory\PostStockOpname;
use App\Actions\Inventory\UpdateStockOpname;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockOpnameRequest;
use App\Models\Category;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StockOpnameController extends Controller
{
    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), [StockOpname::STATUS_DRAFT, StockOpname::STATUS_POSTED], true)
            ? (string) $request->query('status')
            : null;

        $opnames = StockOpname::query()
            ->with('user')
            ->withCount('items')
            ->when($status, fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (StockOpname $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status,
                'createdAt' => $o->created_at?->toIso8601String(),
                'postedAt' => $o->posted_at?->toIso8601String(),
                'user' => $o->user->name,
                'itemsCount' => $o->items_count,
                'note' => $o->note,
            ]);

        return Inertia::render('inventory/stock-opnames/index', [
            'opnames' => $opnames,
            'filters' => ['status' => $status],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('inventory/stock-opnames/form', [
            'opname' => null,
            'products' => ProductOptions::active(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StockOpnameRequest $request, CreateStockOpname $create): RedirectResponse
    {
        $opname = $create->handle($request->user(), $request->counts(), $request->validated('note'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft {$opname->number} disimpan. Stok belum berubah sampai diposting."]);

        return to_route('stock-opnames.show', $opname);
    }

    public function show(StockOpname $stockOpname): Response
    {
        $stockOpname->load(['user', 'items' => fn ($q) => $q->with(['product' => fn ($p) => $p->withTrashed()->with('baseUnit')])]);
        $posted = $stockOpname->isPosted();

        // HPP each difference was booked at, straight from the ledger.
        $costs = $posted
            ? $stockOpname->stockMovements()->pluck('unit_cost', 'product_id')
            : collect();

        $items = $stockOpname->items
            ->sortBy(fn (StockOpnameItem $i) => $i->product->name)
            ->map(function (StockOpnameItem $item) use ($posted, $costs) {
                $system = $posted ? (int) $item->system_qty : $item->product->stock_on_hand;
                $difference = $posted ? (int) $item->difference : $item->counted_qty - $system;
                $unitCost = $posted ? (string) ($costs[$item->product_id] ?? $item->product->avg_cost) : (string) $item->product->avg_cost;

                return [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'product' => $item->product->name,
                    'sku' => $item->product->sku,
                    'baseUnit' => $item->product->baseUnit->name,
                    'systemQty' => $system,
                    'countedQty' => $item->counted_qty,
                    'difference' => $difference,
                    'value' => (int) round((float) Decimal::mul((string) $difference, $unitCost)),
                ];
            })
            ->values()
            ->all();

        return Inertia::render('inventory/stock-opnames/show', [
            'opname' => [
                'id' => $stockOpname->id,
                'number' => $stockOpname->number,
                'status' => $stockOpname->status,
                'note' => $stockOpname->note,
                'user' => $stockOpname->user->name,
                'createdAt' => $stockOpname->created_at?->toIso8601String(),
                'postedAt' => $stockOpname->posted_at?->toIso8601String(),
                'items' => $items,
            ],
        ]);
    }

    public function edit(StockOpname $stockOpname): Response|RedirectResponse
    {
        if ($stockOpname->isPosted()) {
            return to_route('stock-opnames.show', $stockOpname);
        }

        return Inertia::render('inventory/stock-opnames/form', [
            'opname' => [
                'id' => $stockOpname->id,
                'number' => $stockOpname->number,
                'note' => $stockOpname->note,
                'counts' => $stockOpname->items()->pluck('counted_qty', 'product_id'),
            ],
            'products' => ProductOptions::active(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(StockOpnameRequest $request, StockOpname $stockOpname, UpdateStockOpname $update): RedirectResponse
    {
        $update->handle($stockOpname, $request->counts(), $request->validated('note'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft {$stockOpname->number} diperbarui."]);

        return to_route('stock-opnames.show', $stockOpname);
    }

    public function destroy(StockOpname $stockOpname): RedirectResponse
    {
        DB::transaction(function () use ($stockOpname) {
            $locked = StockOpname::query()->whereKey($stockOpname->id)->lockForUpdate()->firstOrFail();
            if ($locked->isPosted()) {
                throw ValidationException::withMessages(['opname' => 'Stok opname yang sudah diposting tidak bisa dihapus.']);
            }
            $locked->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "Draft {$stockOpname->number} dihapus."]);

        return to_route('stock-opnames.index');
    }

    public function post(Request $request, StockOpname $stockOpname, PostStockOpname $post): RedirectResponse
    {
        $posted = $post->handle($stockOpname, $request->user());
        $changed = $posted->items->where('difference', '!=', 0)->count();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$posted->number} diposting. {$changed} produk disesuaikan."]);

        return to_route('stock-opnames.show', $stockOpname);
    }
}
