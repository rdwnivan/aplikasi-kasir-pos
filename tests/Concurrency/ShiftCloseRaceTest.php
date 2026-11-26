<?php

use App\Actions\Sales\CloseShiftForUser;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
| The owner closes a cashier's shift (DESIGN.md §13) while that cashier is checking out in
| another process. The checkout must wait for the close and then be refused, never land on
| a shift whose expected cash has already been written.
*/

it('refuses a checkout that races the owner closing the shift', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $this->seed(RoleSeeder::class);
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 4000]);
    app(StockLedger::class)->receive($product, 5, '3000');

    $owner = User::factory()->create()->assignRole(Role::Owner->value);
    $cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $shift = app(OpenShift::class)->handle($cashier, 10000);

    // The owner's close stays uncommitted, holding the shift row, while the cashier's
    // checkout starts in a second process.
    DB::beginTransaction();
    app(CloseShiftForUser::class)->handle($owner, $shift, 10000, 'Kasir pulang');

    $checkout = startPhpProcess('concurrent_checkout.php', [(string) $cashier->id, (string) $unit->id]);
    usleep(1_500_000);
    expect(proc_get_status($checkout['handle'])['running'])->toBeTrue('The checkout should wait for the shift lock.');

    DB::commit();

    expect(finishProcess($checkout)['error'] ?? null)->toContain('Buka shift dulu sebelum bertransaksi.')
        ->and(Sale::query()->count())->toBe(0)
        ->and($shift->fresh()->expected_cash)->toBe(10000)
        ->and($product->fresh()->stock_on_hand)->toBe(5);
});
