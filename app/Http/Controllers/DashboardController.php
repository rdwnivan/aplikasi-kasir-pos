<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Queries\LowStock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canManageStock = $user->hasAnyRole([Role::Owner->value, Role::Warehouse->value]);

        return Inertia::render('dashboard', [
            'lowStock' => $canManageStock ? [
                'count' => LowStock::count(),
                'items' => LowStock::list(10),
            ] : null,
        ]);
    }
}
