<?php

namespace App\Http\Controllers\Pos;

use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable thermal receipt (58 or 80 mm), rendered as a plain Blade page so it prints the
 * same from any browser without the app shell.
 */
class ReceiptController extends Controller
{
    public function show(Request $request, Sale $sale): View
    {
        abort_unless($sale->status === SaleStatus::Completed, 404);
        abort_unless(
            $sale->user_id === $request->user()->id || $request->user()->hasRole(Role::Owner->value),
            403,
        );

        return view('receipts.sale', [
            'sale' => $sale->load(['items', 'user']),
            'credit' => PosController::creditCustomer($sale),
            'store' => config('pos.store'),
            'width' => $request->integer('w') === 80 ? 80 : 58,
            'autoPrint' => $request->boolean('print'),
            'isCopy' => $request->boolean('copy'),
        ]);
    }
}
