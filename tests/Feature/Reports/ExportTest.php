<?php

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);

    Carbon::setTestNow('2026-11-21 10:00:00');
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Aqua', 'sku' => 'MNM-001', 'base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 11100]);
    app(StockLedger::class)->receive($product, 10, '8000');
    app(OpenShift::class)->handle($this->owner, 0);
    app(CompleteSale::class)->handle($this->owner, [['product_unit_id' => $unit->id, 'qty' => 2]], paidAmount: 22200);
});

/**
 * Save the streamed download and read every sheet back.
 *
 * @return array<string, list<list<mixed>>>
 */
function readXlsx(BinaryFileResponse $response): array
{
    $reader = new Reader;
    $reader->open($response->getFile()->getPathname());

    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = $row->toArray();
        }
    }
    $reader->close();

    return $sheets;
}

it('exports the sales report as a real workbook with numeric cells', function () {
    $response = $this->actingAs($this->owner)
        ->get(route('reports.export.sales', ['from' => '2026-11-20', 'to' => '2026-11-21']));

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertDownload('laporan-penjualan-2026-11-20_2026-11-21.xlsx');

    $rows = readXlsx($response->baseResponse)['Penjualan harian'];

    expect($rows[0][0])->toStartWith('Laporan penjualan & laba kotor')
        ->and($rows[3][0])->toBe('Tanggal') // the reader skips the blank spacer row
        ->and($rows[4])->toBe(['2026-11-20', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0])
        // 22.200 incl. PPN; PPN 2.200; net 20.000; HPP 16.000; profit 4.000 (20 %)
        ->and($rows[5])->toBe(['2026-11-21', 1, 22200, 22200, 0, 0, 0, 2200, 20000, 16000, 4000, 20])
        ->and($rows[6][0])->toBe('Total')
        ->and($rows[6][10])->toBe(4000);
});

it('exports the other reports', function (string $route, string $sheet, string $expectedCell) {
    $response = $this->actingAs($this->owner)->get(route($route, ['from' => '2026-11-21', 'to' => '2026-11-21']));
    $response->assertOk();

    $workbook = readXlsx($response->baseResponse);
    $cells = collect($workbook[$sheet])->flatten()->all();

    expect($cells)->toContain($expectedCell);
})->with([
    'top products' => ['reports.export.products', 'Produk terlaris', 'MNM-001'],
    'inventory by product' => ['reports.export.inventory', 'Per produk', 'Aqua'],
    'inventory by category' => ['reports.export.inventory', 'Per kategori', 'Tanpa kategori'],
    'shifts by cashier' => ['reports.export.shifts', 'Per kasir', 'Budi'],
    'shifts by shift' => ['reports.export.shifts', 'Per shift', 'Budi'],
]);

it('is for the owner only', function () {
    $cashier = User::factory()->create()->assignRole(Role::Cashier->value);

    $this->actingAs($cashier)->get(route('reports.export.sales'))->assertForbidden();
    $this->actingAs($cashier)->get(route('reports.export.inventory'))->assertForbidden();
});
