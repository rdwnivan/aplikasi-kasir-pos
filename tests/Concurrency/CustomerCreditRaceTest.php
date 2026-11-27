<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\WriteOffCustomerDebt;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\CustomerPayment;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

/*
| Two cashiers act for the same customer at the same moment (DESIGN.md §17). The customer
| row lock must make the second wait and then see the first one's effect on the balance.
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

    $this->first = User::factory()->create()->assignRole(Role::Cashier->value);
    $this->second = User::factory()->create()->assignRole(Role::Cashier->value);
    app(OpenShift::class)->handle($this->first, 0);
    app(OpenShift::class)->handle($this->second, 0);
});

it('lets only one of two kasbon through when both together would pass the limit', function () {
    $customer = app(CreateCustomer::class)->handle($this->first, ['name' => 'Bu Sari']);
    $customer->forceFill(['credit_limit' => 50_000])->save();

    DB::beginTransaction();
    app(CompleteSale::class)->handle($this->first, [['product_unit_id' => $this->unit->id, 'qty' => 1]], paidAmount: 0, customer: $customer);

    $other = startPhpProcess('concurrent_credit.php', ['sale', (string) $this->second->id, (string) $customer->id, (string) $this->unit->id]);
    usleep(1_500_000);
    expect(proc_get_status($other['handle'])['running'])->toBeTrue('The second kasbon should wait for the customer lock.');

    DB::commit();

    expect(finishProcess($other)['error'] ?? null)->toContain('Batas kasbon Bu Sari Rp 50.000, sisa Rp 20.000.')
        ->and(Sale::query()->count())->toBe(1)
        ->and($customer->fresh()->balance)->toBe(30_000);
});

it('lets only one of a write-off and a payment take the last of the debt', function () {
    $customer = app(CreateCustomer::class)->handle($this->first, ['name' => 'Bu Sari']);
    app(CompleteSale::class)->handle($this->first, [['product_unit_id' => $this->unit->id, 'qty' => 1]], paidAmount: 0, customer: $customer);
    $owner = User::factory()->create()->assignRole(Role::Owner->value);

    // A write-off takes no document number, so only the customer row lock can hold the
    // payment back (two payments would also queue on the PAY-… number sequence).
    DB::beginTransaction();
    app(WriteOffCustomerDebt::class)->handle($owner, $customer, 'Pindah kota');

    $other = startPhpProcess('concurrent_credit.php', ['pay', (string) $this->second->id, (string) $customer->id, '30000']);
    usleep(1_500_000);
    expect(proc_get_status($other['handle'])['running'])->toBeTrue('The second payment should wait for the customer lock.');

    DB::commit();

    expect(finishProcess($other)['error'] ?? null)->toContain('Bu Sari tidak punya utang.')
        ->and(CustomerPayment::query()->count())->toBe(0)
        ->and($customer->fresh()->balance)->toBe(0);
});
