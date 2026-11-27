<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\WriteOffCustomerDebt;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
| A QRIS pelunasan and an owner's write-off reach for the same debt at once (DESIGN.md §18).
| The customer row lock in StartQrisCustomerPayment must make the QR wait and then see that
| nothing is owed, so a QR can never wait on a debt that is already gone. A write-off takes
| no PAY-… number, so only the customer lock can hold the QR back.
*/

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Needs PostgreSQL (row-level locks across two connections).');
    }

    $this->seed(RoleSeeder::class);
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 30_000]);
    app(StockLedger::class)->receive($product, 10, '20000');

    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    app(OpenShift::class)->handle($this->cashier, 0);
});

it('lets only one of a write-off and a QRIS pelunasan take the debt', function () {
    $customer = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Sari']);
    app(CompleteSale::class)->handle($this->cashier, [['product_unit_id' => $this->unit->id, 'qty' => 1]], paidAmount: 0, customer: $customer);

    DB::beginTransaction();
    app(WriteOffCustomerDebt::class)->handle($this->owner, $customer, 'Pindah kota');

    $other = startPhpProcess('concurrent_qris_payment.php', [(string) $this->cashier->id, (string) $customer->id, '30000']);
    usleep(1_500_000);
    expect(proc_get_status($other['handle'])['running'])->toBeTrue('The QRIS pelunasan should wait for the customer lock.');

    DB::commit();

    expect(finishProcess($other)['error'] ?? null)->toContain('Bu Sari tidak punya utang.')
        ->and(CustomerPayment::query()->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(0);
});
