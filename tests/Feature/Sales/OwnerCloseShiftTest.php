<?php

use App\Actions\Sales\CloseShift;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\HoldSale;
use App\Actions\Sales\OpenShift;
use App\Actions\Sales\StartQrisPayment;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUser;
use App\Enums\Role;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Reports\ShiftRecap;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

/*
| DESIGN.md §13: an owner closes a shift another user left open.
*/

beforeEach(function () {
    Carbon::setTestNow('2026-11-26 10:00:00');
    config([
        'services.midtrans.server_key' => 'SB-Mid-server-TESTONLY',
        'services.midtrans.is_production' => false,
        'pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true],
    ]);
    $this->seed(RoleSeeder::class);

    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
    $this->staff = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Warehouse->value);

    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->product = Product::factory()->create(['name' => 'Aqua', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($this->product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($this->product, 10, '3000');

    $this->shift = app(OpenShift::class)->handle($this->cashier, 50000);
    Carbon::setTestNow('2026-11-26 10:05:00');
    app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 2]], paidAmount: 10000);
    Carbon::setTestNow('2026-11-26 10:30:00');

    $this->midtrans = ['status' => 'pending', 'down' => false];
    Http::fake(function (HttpRequest $request) {
        $url = $request->url();

        if (str_ends_with($url, '/v2/charge')) {
            return Http::response([
                'status_code' => '201',
                'transaction_id' => 'trx-'.$request['transaction_details']['order_id'],
                'order_id' => $request['transaction_details']['order_id'],
                'gross_amount' => $request['transaction_details']['gross_amount'].'.00',
                'transaction_status' => 'pending',
                'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/x/qr-code']],
                'qr_string' => '00020101021226...',
                'expiry_time' => '2026-11-26 10:45:00',
            ], 201);
        }

        if (test()->midtrans['down']) {
            return Http::response(['status_code' => '500', 'status_message' => 'Internal error'], 500);
        }

        if (str_ends_with($url, '/status')) {
            $status = test()->midtrans['status'];

            return Http::response(['status_code' => $status === 'settlement' ? '200' : '201', 'transaction_status' => $status]);
        }

        if (str_ends_with($url, '/cancel')) {
            return Http::response(['status_code' => '200', 'transaction_status' => 'cancel']);
        }

        return Http::response([], 404);
    });
});

/** A mixed payment left waiting on the cashier's shift: 3 pcs = Rp 12.000, Rp 5.000 of it in cash. */
function pendingSplitOnShift(): Sale
{
    return app(StartQrisPayment::class)->handle(
        test()->cashier,
        [['product_unit_id' => test()->unit->id, 'qty' => 3]],
        cashAmount: 5000,
    );
}

function closeAsOwner(array $data = []): TestResponse
{
    return test()->actingAs(test()->owner)->put(
        route('reports.shifts.close.update', test()->shift),
        $data + ['counted_cash' => 57000, 'reason' => 'Kasir pulang, lupa tutup shift'],
    );
}

describe('access', function () {
    it('is for owners only', function (string $who) {
        $user = $this->{$who};

        $this->actingAs($user)->get(route('reports.shifts.close.edit', $this->shift))->assertForbidden();
        $this->actingAs($user)->put(route('reports.shifts.close.update', $this->shift), ['counted_cash' => 0, 'reason' => 'x'])->assertForbidden();

        expect($this->shift->fresh()->isOpen())->toBeTrue();
    })->with(['cashier', 'staff']);

    it('sends the owner to the till screen for their own shift', function () {
        $own = app(OpenShift::class)->handle($this->owner, 0);

        $this->actingAs($this->owner)->get(route('reports.shifts.close.edit', $own))
            ->assertRedirect(route('pos.shift.edit'));
        $this->actingAs($this->owner)->put(route('reports.shifts.close.update', $own), ['counted_cash' => 0, 'reason' => 'x'])
            ->assertRedirect(route('pos.shift.edit'));

        expect($own->fresh()->isOpen())->toBeTrue();
    });

    it('sends the owner back to the recap when the shift is already closed', function () {
        app(CloseShift::class)->handle($this->shift, 58000);

        $this->actingAs($this->owner)->get(route('reports.shifts.close.edit', $this->shift))
            ->assertRedirect(route('reports.shifts'))
            ->assertSessionHas('inertia.flash_data.toast.message', 'Shift ini sudah ditutup.');
    });
});

it('shows the drawer, waiting QRIS, held carts and the last sale before closing', function () {
    $pending = pendingSplitOnShift();
    app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 1]], 'Ibu baju merah');

    $this->actingAs($this->owner)->get(route('reports.shifts.close.edit', $this->shift))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/close-shift')
            ->where('cashier', 'Andi')
            ->where('shift.id', $this->shift->id)
            ->where('shift.openingCash', 50000)
            ->where('shift.cashSalesTotal', 8000)
            ->where('shift.expectedCash', 58000)
            ->where('heldCount', 1)
            ->has('pendingQris', 1)
            ->where('pendingQris.0.number', $pending->number)
            ->where('pendingQris.0.total', 12000)
            ->where('pendingQris.0.cashAmount', 5000)
            ->where('lastSaleAt', Carbon::parse('2026-11-26 10:05:00')->toIso8601String()));
});

it('closes the shift with the counted drawer and records who closed it and why', function () {
    closeAsOwner()
        ->assertRedirect(route('reports.shifts'))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Shift Andi ditutup. Kas kurang Rp 1.000.');

    expect($this->shift->fresh())
        ->closed_at->not->toBeNull()
        ->expected_cash->toBe(58000)
        ->counted_cash->toBe(57000)
        ->cash_difference->toBe(-1000)
        ->closed_by->toBe($this->owner->id)
        ->close_reason->toBe('Kasir pulang, lupa tutup shift');
});

it('requires a reason and a counted amount that is not negative', function (array $data, string $field) {
    closeAsOwner($data)->assertSessionHasErrors($field);

    expect($this->shift->fresh()->isOpen())->toBeTrue();
})->with([
    'no reason' => [['reason' => ''], 'reason'],
    'reason too long' => [['reason' => str_repeat('a', 256)], 'reason'],
    'no count' => [['counted_cash' => ''], 'counted_cash'],
    'negative count' => [['counted_cash' => -1], 'counted_cash'],
]);

describe('a QRIS still waiting on the shift', function () {
    it('is booked when Midtrans says it was paid, so its cash part is expected in the drawer', function () {
        $sale = pendingSplitOnShift();
        $this->midtrans['status'] = 'settlement';

        closeAsOwner(['counted_cash' => 63000])->assertSessionHasNoErrors();

        expect($sale->fresh()->status)->toBe(SaleStatus::Completed)
            ->and($this->product->fresh()->stock_on_hand)->toBe(5) // 10 − 2 − 3
            ->and(StockMovement::query()->where('type', StockMovementType::Sale)->where('reference_id', $sale->id)->count())->toBe(1)
            ->and($this->shift->fresh())
            ->expected_cash->toBe(63000) // 50.000 + 8.000 + 5.000 cash part
            ->cash_difference->toBe(0);
    });

    it('is cancelled at Midtrans when unpaid, and its cash part is not expected', function () {
        $sale = pendingSplitOnShift();

        closeAsOwner(['counted_cash' => 58000])->assertSessionHasNoErrors();

        expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
            ->and($this->product->fresh()->stock_on_hand)->toBe(8)
            ->and($this->shift->fresh()->expected_cash)->toBe(58000);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), "/v2/{$sale->number}/cancel"));
    });

    it('is cancelled here even when Midtrans cannot be reached', function () {
        $sale = pendingSplitOnShift();
        $this->midtrans['down'] = true;

        closeAsOwner()->assertSessionHasNoErrors();

        expect($sale->fresh()->status)->toBe(SaleStatus::Cancelled)
            ->and($this->shift->fresh()->isOpen())->toBeFalse();
    });
});

it('discards the held carts of that shift only', function () {
    $mine = app(HoldSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 1]]);
    $other = User::factory()->create()->assignRole(Role::Cashier->value);
    app(OpenShift::class)->handle($other, 0);
    $theirs = app(HoldSale::class)->handle($other, [['product_unit_id' => $this->unit->id, 'qty' => 1]]);

    closeAsOwner()->assertSessionHasNoErrors();

    expect(Sale::query()->find($mine->id))->toBeNull()
        ->and(Sale::query()->find($theirs->id)?->status)->toBe(SaleStatus::Held);
});

describe('the cashier whose shift was closed', function () {
    it('cannot sell, hold, start a QRIS or refund on it any more', function () {
        $sale = Sale::query()->where('shift_id', $this->shift->id)->sole();
        closeAsOwner();
        $items = ['items' => [['product_unit_id' => $this->unit->id, 'qty' => 1]]];

        $this->actingAs($this->cashier)->post(route('pos.sales.store'), $items + ['paid_amount' => 4000])
            ->assertSessionHasErrors(['shift' => 'Buka shift dulu sebelum bertransaksi.']);
        $this->actingAs($this->cashier)->post(route('pos.held.store'), $items)
            ->assertSessionHasErrors('shift');
        $this->actingAs($this->cashier)->post(route('pos.qris.store'), $items)
            ->assertSessionHasErrors('shift');
        $this->actingAs($this->cashier)->post(route('pos.sales.returns.store', $sale), ['items' => [['sale_item_id' => $sale->items()->value('id'), 'qty' => 1]], 'owner_pin' => '1234'])
            ->assertSessionHasErrors('shift');

        expect(Sale::query()->where('status', '!=', SaleStatus::Cancelled)->count())->toBe(1);
    });

    it('is told on the till who closed the shift, until a new one is opened', function () {
        closeAsOwner();

        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/open-shift')
                ->where('closedByOther.by', 'Budi')
                ->where('closedByOther.at', Carbon::parse('2026-11-26 10:30:00')->toIso8601String())
                ->where('closedByOther.reason', 'Kasir pulang, lupa tutup shift'));

        $next = app(OpenShift::class)->handle($this->cashier, 0);
        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->component('pos/index'));

        app(CloseShift::class)->handle($next, 0);
        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->component('pos/open-shift')->where('closedByOther', null));
    });
});

it('records the cashier as the closer when they close their own shift', function () {
    $this->actingAs($this->cashier)->put(route('pos.shift.update'), ['counted_cash' => 58000])
        ->assertRedirect(route('pos.index'));

    expect($this->shift->fresh())
        ->closed_by->toBe($this->cashier->id)
        ->close_reason->toBeNull();
});

it('shows who closed the shift in the recap and the export only when it was someone else', function () {
    $own = User::factory()->create(['name' => 'Rina'])->assignRole(Role::Cashier->value);
    $ownShift = app(OpenShift::class)->handle($own, 0);
    app(CloseShift::class)->handle($ownShift, 0, closedBy: $own);
    $legacy = app(OpenShift::class)->handle(User::factory()->create(['name' => 'Lama']), 0);
    app(CloseShift::class)->handle($legacy, 0);
    $legacy->forceFill(['closed_by' => null])->save(); // closed before the column existed
    closeAsOwner();

    $rows = collect(ShiftRecap::build(DateRange::of('2026-11-26', '2026-11-26'))['shifts'])->keyBy('cashier');

    expect($rows['Andi'])->toMatchArray(['closedBy' => 'Budi', 'closeReason' => 'Kasir pulang, lupa tutup shift'])
        ->and($rows['Rina'])->toMatchArray(['closedBy' => null, 'closeReason' => null])
        ->and($rows['Lama'])->toMatchArray(['closedBy' => null, 'closeReason' => null]);

    $response = $this->actingAs($this->owner)->get(route('reports.export.shifts', ['from' => '2026-11-26', 'to' => '2026-11-26']));
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $sheet = [];
    foreach ($reader->getSheetIterator() as $s) {
        if ($s->getName() === 'Per shift') {
            foreach ($s->getRowIterator() as $row) {
                $sheet[] = $row->toArray();
            }
        }
    }
    $reader->close();

    $header = $sheet[3];
    $andi = collect($sheet)->first(fn (array $row) => $row[0] === 'Andi');
    $rina = collect($sheet)->first(fn (array $row) => $row[0] === 'Rina');

    expect(array_slice($header, -2))->toBe(['Ditutup oleh', 'Alasan'])
        ->and(array_slice($andi, -2))->toBe(['Budi', 'Kasir pulang, lupa tutup shift'])
        ->and(array_slice($rina, -2))->toBe(['', '']);
});

it('lets the owner deactivate the account or make it warehouse staff once the shift is closed', function () {
    closeAsOwner();

    app(UpdateUser::class)->handle($this->owner, $this->cashier->fresh(), ['name' => 'Andi', 'email' => $this->cashier->email, 'role' => Role::Warehouse]);
    app(DeactivateUser::class)->handle($this->owner, $this->cashier->fresh());

    expect($this->cashier->fresh()->deactivated_at)->not->toBeNull();
});

it('points the users page at the open shift to close', function () {
    $this->actingAs($this->owner)->get(route('users.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('users.0.name', 'Andi')
            ->where('users.0.open_shift_id', $this->shift->id)
            ->where('users.1.open_shift_id', null));
});

it('lets the owner close the public demo cashier’s shift', function () {
    config(['pos.protect_demo_accounts' => true]);
    $this->cashier->forceFill(['email' => 'kasir@kasir.test'])->save();

    closeAsOwner()->assertSessionHasNoErrors();

    expect(Shift::query()->find($this->shift->id)->closed_by)->toBe($this->owner->id);
});
