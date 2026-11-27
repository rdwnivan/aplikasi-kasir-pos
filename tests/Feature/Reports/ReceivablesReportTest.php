<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Actions\Sales\OpenShift;
use App\Enums\Role;
use App\Exports\ReportPdf;
use App\Exports\ReportTables;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Reports\Receivables;
use App\Services\Inventory\StockLedger;
use App\Support\DateRange;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    config(['pos.tax' => ['enabled' => true, 'rate' => '12', 'dpp_numerator' => 11, 'dpp_denominator' => 12, 'prices_include_tax' => true]]);
    config(['pos.credit.default_limit' => 5_000_000]);
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);

    Carbon::setTestNow('2026-07-01 08:00:00');
    $pcs = Unit::factory()->create(['name' => 'pcs']);
    $product = Product::factory()->create(['name' => 'Rokok', 'base_unit_id' => $pcs->id]);
    $this->unit = ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id, 'conversion' => 1, 'price' => 10_000]);
    app(StockLedger::class)->receive($product, 500, '8000');
    app(OpenShift::class)->handle($this->owner, 0);

    $this->sari = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Bu Sari', 'phone' => '0812']);
    $this->rt = app(CreateCustomer::class)->handle($this->owner, ['name' => 'Pak RT']);
    app(CreateCustomer::class)->handle($this->owner, ['name' => 'Lunas Terus']);

    // Today is 27 Nov 2026. Bu Sari: 100 days, 70 days, 40 days and today; Pak RT: 31 days.
    $sell = function ($customer, int $qty, string $at) {
        Carbon::setTestNow($at);
        app(CompleteSale::class)->handle($this->owner, [['product_unit_id' => $this->unit->id, 'qty' => $qty]], paidAmount: 0, customer: $customer);
    };
    $sell($this->sari, 1, '2026-08-19 19:00'); // 100 days → > 90
    $sell($this->sari, 2, '2026-09-18 19:00'); // 70 days → 61–90
    $sell($this->sari, 3, '2026-10-18 19:00'); // 40 days → 31–60
    $sell($this->sari, 4, '2026-11-27 07:00'); // today → 0–30
    $sell($this->rt, 5, '2026-10-27 23:30');   // 31 days → 31–60
    Carbon::setTestNow('2026-11-20 10:00');
    app(ReceiveCustomerPayment::class)->handle($this->owner, $this->sari, 15_000); // clears the oldest, half of the next

    Carbon::setTestNow('2026-11-27 12:00:00');
});

it('ages what is owed per kasbon, per customer, as of today', function () {
    $report = Receivables::build();

    expect($report['rows'])->toHaveCount(2)
        ->and($report['rows'][0])->toMatchArray([
            'customer' => 'Bu Sari',
            'phone' => '0812',
            'balance' => 85_000,
            'creditLimit' => 5_000_000,
            'age0to30' => 40_000,
            'age31to60' => 30_000,
            'age61to90' => 15_000,
            'age90plus' => 0,
            'openSales' => 3,
            'oldestAt' => '2026-09-18T19:00:00+07:00',
            'lastPaymentAt' => '2026-11-20T10:00:00+07:00',
        ])
        ->and($report['rows'][1])->toMatchArray(['customer' => 'Pak RT', 'balance' => 50_000, 'age31to60' => 50_000, 'lastPaymentAt' => null])
        ->and($report['totals'])->toMatchArray([
            'customers' => 2,
            'balance' => 135_000,
            'age0to30' => 40_000,
            'age31to60' => 80_000,
            'age61to90' => 15_000,
            'age90plus' => 0,
            'overdue' => 95_000, // older than 30 days
        ]);
});

it('shows the report page to the owner', function () {
    $this->actingAs($this->owner)->get(route('reports.receivables'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/receivables')
            ->where('report.totals.balance', 135_000)
            ->where('report.rows.0.customer', 'Bu Sari'));
});

it('exports the same table to Excel and PDF', function () {
    $document = ReportTables::receivables();
    expect($document->filename)->toBe('laporan-piutang-2026-11-27')
        ->and($document->landscape)->toBeFalse();

    $response = $this->actingAs($this->owner)->get(route('reports.export.receivables'));
    $response->assertOk()->assertDownload('laporan-piutang-2026-11-27.xlsx');

    /** @var BinaryFileResponse $file */
    $file = $response->baseResponse;
    $reader = new Reader;
    $reader->open($file->getFile()->getPathname());
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    $header = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Pelanggan');
    expect($header)->toBe(['Pelanggan', 'No. HP', 'Utang', '0–30 hari', '31–60 hari', '61–90 hari', '> 90 hari', 'Kasbon tertua', 'Pelunasan terakhir', 'Batas kredit']);
    $sari = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Bu Sari');
    expect(array_slice($sari, 0, 7))->toBe(['Bu Sari', '0812', 85_000, 40_000, 30_000, 15_000, 0]);
    expect(collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Total')[2])->toBe(135_000);

    $pdf = $this->actingAs($this->owner)->get(route('reports.pdf.receivables'));
    $pdf->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload('laporan-piutang-2026-11-27.pdf');

    $html = app(ReportPdf::class)->html($document, $this->owner);
    expect($html)->toContain('Bu Sari')->toContain('Rp 85.000')->toContain('Rp 135.000');
});

it('adds a Kasbon column to the sales and shift exports', function () {
    $sales = ReportTables::sales(DateRange::of('2026-11-27', '2026-11-27'), 'day');
    $labels = array_column($sales->sheets()[0]['columns'], 'label');
    expect($labels)->toContain('Kasbon')
        ->and(array_search('Kasbon', $labels))->toBe(array_search('QRIS', $labels) + 1);

    $shifts = ReportTables::shifts(DateRange::of('2026-07-01', '2026-11-27'));
    foreach ($shifts->sheets() as $table) {
        // Cash and QRIS pelunasan apart: only cash goes in the drawer (DESIGN.md §18).
        expect(array_column($table['columns'], 'label'))->toContain('Kasbon')->toContain('Pelunasan tunai')->toContain('Pelunasan QRIS');
    }
    expect(count($shifts->sheets()[0]['columns']))->toBe(10)
        ->and(count($shifts->sheets()[1]['columns']))->toBe(16);
});
