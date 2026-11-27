<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Customers\CreateCustomer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "+ Pelanggan baru" in the kasbon dialog: a cashier adds a customer (name and phone) without
 * waiting for the owner, always with the default credit limit (DESIGN.md §17).
 */
class CustomerController extends Controller
{
    public function store(Request $request, CreateCustomer $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
        ], attributes: ['name' => 'nama pelanggan', 'phone' => 'nomor HP']);

        $customer = $create->handle($request->user(), $data);

        Inertia::flash('customer', ['id' => $customer->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Pelanggan {$customer->name} ditambahkan."]);

        return back();
    }
}
