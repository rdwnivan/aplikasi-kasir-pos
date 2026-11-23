<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('catalog/suppliers', [
            'items' => Supplier::query()->withCount('goodsReceipts')->orderBy('name')->get()
                ->map(fn (Supplier $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'phone' => $s->phone,
                    'address' => $s->address,
                    'usage' => $s->goods_receipts_count,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = Supplier::create($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => "Supplier {$supplier->name} ditambahkan."]);

        return back();
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Supplier disimpan.']);

        return back();
    }

    /** A supplier on a goods receipt stays, so the receipt keeps saying who delivered. */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->purchaseOrders()->exists()) {
            throw ValidationException::withMessages(['supplier' => "Supplier {$supplier->name} sudah dipakai di pesanan pembelian, tidak bisa dihapus."]);
        }
        if ($supplier->goodsReceipts()->exists()) {
            throw ValidationException::withMessages(['supplier' => "Supplier {$supplier->name} sudah dipakai di penerimaan barang, tidak bisa dihapus."]);
        }

        $supplier->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => "Supplier {$supplier->name} dihapus."]);

        return back();
    }

    /** @return array{name: string, phone: string|null, address: string|null} */
    private function validated(Request $request): array
    {
        /** @var array{name: string, phone: string|null, address: string|null} */
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], attributes: ['name' => 'nama supplier', 'phone' => 'telepon', 'address' => 'alamat']);
    }
}
