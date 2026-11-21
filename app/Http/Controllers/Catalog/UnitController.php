<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UnitController extends Controller
{
    public function index(): Response
    {
        $usage = ProductUnit::query()->selectRaw('unit_id, COUNT(*) AS n')->groupBy('unit_id')->pluck('n', 'unit_id');

        return Inertia::render('catalog/units', [
            'items' => Unit::query()->orderBy('name')->get()
                ->map(fn (Unit $u) => ['id' => $u->id, 'name' => $u->name, 'usage' => (int) ($usage[$u->id] ?? 0)]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = Unit::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Satuan {$unit->name} ditambahkan."]);

        return back();
    }

    public function update(Request $request, Unit $unit): RedirectResponse
    {
        $unit->update($this->validated($request, $unit));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Satuan disimpan.']);

        return back();
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $inUse = Product::withTrashed()->where('base_unit_id', $unit->id)->exists()
            || ProductUnit::query()->where('unit_id', $unit->id)->exists()
            || GoodsReceiptItem::query()->where('unit_id', $unit->id)->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['unit' => "Satuan {$unit->name} masih dipakai produk atau dokumen, tidak bisa dihapus."]);
        }

        $unit->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Satuan {$unit->name} dihapus."]);

        return back();
    }

    /** @return array{name: string} */
    private function validated(Request $request, ?Unit $unit = null): array
    {
        $request->merge(['name' => mb_strtolower(trim((string) $request->input('name')))]);

        /** @var array{name: string} */
        return $request->validate(
            ['name' => ['required', 'string', 'max:30', Rule::unique('units', 'name')->ignore($unit?->id)]],
            attributes: ['name' => 'nama satuan'],
        );
    }
}
