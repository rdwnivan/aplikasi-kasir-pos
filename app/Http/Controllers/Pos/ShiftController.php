<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\OpenShift;
use App\Enums\SaleStatus;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;

class ShiftController extends Controller
{
    public function store(Request $request, OpenShift $open): RedirectResponse
    {
        $data = $request->validate([
            'opening_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $open->handle($request->user(), (int) $data['opening_cash'], $data['note'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Shift dibuka. Selamat bertugas!']);

        return to_route('pos.index');
    }

    public function edit(Request $request): Response|RedirectResponse
    {
        $shift = $this->openShift($request);

        if ($shift === null) {
            return to_route('pos.index');
        }

        return Inertia::render('pos/close-shift', [
            'shift' => PosController::shiftSummary($shift),
            'heldCount' => $shift->sales()->where('status', SaleStatus::Held)->count(),
        ]);
    }

    public function update(Request $request, CloseShift $close): RedirectResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->openShift($request) ?? abort(404);
        $closed = $close->handle($shift, (int) $data['counted_cash'], $data['note'] ?? null, closedBy: $request->user());

        self::flashClosed($closed, 'Shift ditutup.');

        return to_route('pos.index');
    }

    /** Toast after a close: "{prefix} Kas pas / kurang Rp … / lebih Rp …". */
    public static function flashClosed(Shift $closed, string $prefix): void
    {
        $difference = (int) $closed->cash_difference;
        $message = match (true) {
            $difference === 0 => "{$prefix} Kas pas.",
            $difference < 0 => "{$prefix} Kas kurang ".self::rupiah(-$difference).'.',
            default => "{$prefix} Kas lebih ".self::rupiah($difference).'.',
        };
        Inertia::flash('toast', ['type' => $difference === 0 ? 'success' : 'warning', 'message' => $message]);
    }

    private function openShift(Request $request): ?Shift
    {
        return Shift::query()->open()->where('user_id', $request->user()->id)->first();
    }

    private static function rupiah(int $amount): string
    {
        return 'Rp '.Number::format($amount, locale: 'id');
    }
}
