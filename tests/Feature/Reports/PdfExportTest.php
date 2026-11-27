<?php

use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Exports\ReportDocument;
use App\Exports\ReportPdf;
use App\Exports\ReportTables;
use App\Exports\Spreadsheet;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    config(['pos.store.name' => 'Toko Uji', 'pos.store.address' => 'Jl. Uji No. 1', 'pos.store.npwp' => '01.234.567.8-901.000']);
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);

    Carbon::setTestNow('2026-11-21 10:00:00');
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->product = Product::factory()->create(['name' => 'Aqua', 'sku' => 'MNM-001', 'base_unit_id' => $pcs->id]);
    $unit = ProductUnit::factory()->for($this->product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 11100]);
    app(StockLedger::class)->receive($this->product, 10, '8000');
    app(OpenShift::class)->handle($this->owner, 0);
    app(CompleteSale::class)->handle($this->owner, [['product_unit_id' => $unit->id, 'qty' => 2]], paidAmount: 22200);
});

/** The HTML that goes into dompdf for one report document. */
function pdfHtml(ReportDocument $document, User $by): string
{
    return app(ReportPdf::class)->html($document, $by);
}

it('downloads the sales report as a PDF', function () {
    $response = $this->actingAs($this->owner)
        ->get(route('reports.pdf.sales', ['from' => '2026-11-20', 'to' => '2026-11-21']));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload('laporan-penjualan-2026-11-20_2026-11-21.pdf');

    expect($response->getContent())->toStartWith('%PDF-');
});

it('downloads every report as a PDF', function (string $route, string $filename) {
    $response = $this->actingAs($this->owner)->get(route($route, ['from' => '2026-11-21', 'to' => '2026-11-21']));

    $response->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload($filename);
    expect($response->getContent())->toStartWith('%PDF-');
})->with([
    'top products' => ['reports.pdf.products', 'laporan-produk-terlaris-2026-11-21_2026-11-21.pdf'],
    'inventory' => ['reports.pdf.inventory', 'nilai-persediaan-2026-11-21.pdf'],
    'shifts' => ['reports.pdf.shifts', 'laporan-rekap-kasir-2026-11-21_2026-11-21.pdf'],
]);

it('prints the same sales figures as the workbook, formatted for reading', function () {
    $html = pdfHtml(ReportTables::sales(DateRange::of('2026-11-20', '2026-11-21'), 'day'), $this->owner);

    expect($html)
        ->toContain('Laporan penjualan &amp; laba kotor')
        ->toContain('Toko Uji')
        ->toContain('Jl. Uji No. 1')
        ->toContain('NPWP 01.234.567.8-901.000')
        ->toContain('Periode 20 Nov 2026 s.d. 21 Nov 2026 (WIB)')
        ->toContain('Laba kotor = penjualan tanpa PPN, setelah retur, dikurangi HPP.')
        ->toContain('Dicetak 21 Nov 2026 10.00 WIB oleh Budi')
        ->toContain('21 Nov 2026')
        ->toContain('Rp 22.200')
        ->toContain('Rp 2.200')
        ->toContain('Rp 16.000')
        ->toContain('Rp 4.000')
        ->toContain('20,0%')
        ->toContain('Total')
        ->toContain('A4 landscape');
});

it('groups sales by month with month names', function () {
    $html = pdfHtml(ReportTables::sales(DateRange::of('2026-11-01', '2026-11-30'), 'month'), $this->owner);

    expect($html)->toContain('Penjualan bulanan')->toContain('Nov 2026')->toContain('Bulan');
});

it('prints every section of the multi-sheet reports', function (Closure $document, array $expected, string $paper) {
    $html = pdfHtml($document(), $this->owner);

    foreach ($expected as $text) {
        expect($html)->toContain($text);
    }
    expect($html)->toContain($paper);
})->with([
    'top products' => [fn () => ReportTables::products(DateRange::of('2026-11-21', '2026-11-21')), ['Produk terlaris', 'MNM-001', 'Aqua'], 'A4 landscape'],
    'inventory' => [fn () => ReportTables::inventory(), ['Per produk', 'Per kategori', 'Tanpa kategori', 'Rp 8.000,00', 'Per 21 Nov 2026 10.00 WIB'], 'A4 portrait'],
    'shifts' => [fn () => ReportTables::shifts(DateRange::of('2026-11-21', '2026-11-21')), ['Per kasir', 'Per shift', 'Budi', '21 Nov 2026 10.00'], 'A4 landscape'],
]);

it('formats cells the Indonesian way', function (mixed $value, ?string $format, ?string $kind, string $expected) {
    $column = array_filter(['label' => 'X', 'format' => $format, 'kind' => $kind]);

    expect(ReportPdf::cell($value, $column))->toBe($expected);
})->with([
    'rupiah' => [1500000, Spreadsheet::RUPIAH, null, 'Rp 1.500.000'],
    'negative rupiah (a refund)' => [-2500, Spreadsheet::RUPIAH, null, '-Rp 2.500'],
    'zero rupiah' => [0, Spreadsheet::RUPIAH, null, 'Rp 0'],
    'rupiah with cents (average cost)' => ['8123.4567', Spreadsheet::RUPIAH_CENTS, null, 'Rp 8.123,46'],
    'quantity' => [1234, Spreadsheet::NUMBER, null, '1.234'],
    'percent' => [12.5, Spreadsheet::PERCENT, null, '12,5%'],
    'negative percent' => [-3, Spreadsheet::PERCENT, null, '-3,0%'],
    'day' => ['2026-11-05', null, 'date', '5 Nov 2026'],
    'month' => ['2026-11', null, 'month', 'Nov 2026'],
    'time' => ['2026-11-21 08:05', null, 'datetime', '21 Nov 2026 08.05'],
    'empty time (shift still open)' => ['', null, 'datetime', ''],
    'text' => ['Aqua', null, null, 'Aqua'],
    'empty footer cell' => ['', Spreadsheet::RUPIAH, null, ''],
]);

it('escapes names from the database', function () {
    $this->product->update(['name' => '<b>Kopi</b>']);

    $html = pdfHtml(ReportTables::inventory(), $this->owner);

    expect($html)->toContain('&lt;b&gt;Kopi&lt;/b&gt;')->not->toContain('<b>Kopi</b>');
});

it('refuses a PDF with more rows than the limit, pointing to Excel', function () {
    config(['pos.exports.pdf_max_cells' => 22]); // 2 rows of the 11 sales columns

    $this->actingAs($this->owner)
        ->from(route('reports.sales'))
        ->get(route('reports.pdf.sales', ['from' => '2026-11-19', 'to' => '2026-11-21']))
        ->assertRedirect(route('reports.sales'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error')
        ->assertSessionHas('inertia.flash_data.toast.message', 'Terlalu banyak baris untuk PDF (3, maksimal 2). Persempit periode atau pakai Ekspor Excel.');
});

it('still makes the PDF at exactly the limit', function () {
    config(['pos.exports.pdf_max_cells' => 22]); // 2 rows of the 11 sales columns

    $this->actingAs($this->owner)
        ->get(route('reports.pdf.sales', ['from' => '2026-11-20', 'to' => '2026-11-21']))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('counts cells, so a narrower table may have more rows', function () {
    config(['pos.exports.pdf_max_cells' => 21]); // 1 row of the 11 sales columns, 3 of the 7 inventory ones

    $this->actingAs($this->owner)
        ->get(route('reports.pdf.sales', ['from' => '2026-11-20', 'to' => '2026-11-21']))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Terlalu banyak baris untuk PDF (2, maksimal 1). Persempit periode atau pakai Ekspor Excel.');

    $this->actingAs($this->owner)->get(route('reports.pdf.inventory'))->assertOk()->assertHeader('content-type', 'application/pdf');
});

it('splits long tables into short ones, each with the header, and totals once', function () {
    $html = pdfHtml(ReportTables::sales(DateRange::of('2026-09-23', '2026-11-21'), 'day'), $this->owner); // 60 days

    expect(substr_count($html, '<table>'))->toBe(2) // 50 + 10 rows
        ->and(substr_count($html, '<th class="num">Margin</th>'))->toBe(2)
        ->and(substr_count($html, '<tr class="total">'))->toBe(1)
        ->and($html)->toContain('23 Sep 2026')->toContain('21 Nov 2026');
});

it('does not let dompdf load remote files, PHP or JavaScript', function () {
    $options = app(ReportPdf::class)->dompdf()->getOptions();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse();
});

it('is for the owner only', function (string $route) {
    $this->actingAs(User::factory()->create()->assignRole(Role::Cashier->value))->get(route($route))->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole(Role::Warehouse->value))->get(route($route))->assertForbidden();

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['reports.pdf.sales', 'reports.pdf.products', 'reports.pdf.inventory', 'reports.pdf.shifts']);
