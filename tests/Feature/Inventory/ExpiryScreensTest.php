<?php

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Exports\ReportPdf;
use App\Exports\ReportTables;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Switching tracking on from the product form, and where expiry shows: stock card, dashboard,
 * the expiry report and its exports (DESIGN.md §19.3).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    config(['pos.expiry.warn_days' => 7]);
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    $this->staff = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
    $this->ledger = app(StockLedger::class);

    $this->pcs = Unit::factory()->create(['name' => 'pcs']);
    $this->category = Category::factory()->create(['name' => 'Susu']);
    $this->susu = Product::factory()->create(['sku' => 'SUS-001', 'name' => 'Susu UHT', 'base_unit_id' => $this->pcs->id, 'category_id' => $this->category->id]);
    ProductUnit::factory()->for($this->susu)->create(['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 6000]);
});

/** Susu with an expired batch, one expiring within the warning window, and a safe one. */
function stockSusu(object $test): void
{
    $test->ledger->startTracking($test->susu);
    Carbon::setTestNow('2026-11-20 10:00:00');
    $test->ledger->receive($test->susu, 10, '5000', batches: [
        ['expires_on' => '2026-11-25', 'qty' => 2],             // expired 2 days ago
        ['expires_on' => '2026-11-30', 'qty' => 3, 'code' => 'L5'], // 3 days left
        ['expires_on' => '2027-02-01', 'qty' => 5],             // safe
    ]);
    Carbon::setTestNow('2026-11-27 10:00:00');
}

it('switches tracking on and off from the product form', function () {
    $this->ledger->receive($this->susu, 6, '5000');
    $payload = fn (bool $tracks) => [
        'sku' => 'SUS-001', 'name' => 'Susu UHT', 'category_id' => $this->category->id, 'base_unit_id' => $this->pcs->id,
        'min_stock' => 0, 'is_active' => true, 'tracks_expiry' => $tracks,
        'units' => [['unit_id' => $this->pcs->id, 'conversion' => 1, 'price' => 6000, 'barcode' => '']],
    ];

    $this->actingAs($this->staff)->put(route('products.update', $this->susu), $payload(true))->assertSessionHasNoErrors();

    expect($this->susu->fresh()->tracks_expiry)->toBeTrue()
        ->and(StockBatch::query()->whereNull('expires_on')->sole()->qty_on_hand)->toBe(6)
        ->and(StockMovement::query()->where('type', StockMovementType::Adjustment)->whereNotNull('user_id')->count())->toBe(2);

    $this->actingAs($this->staff)->get(route('products.edit', $this->susu))
        ->assertInertia(fn (Assert $page) => $page->where('product.tracks_expiry', true));
    $this->actingAs($this->staff)->get(route('products.index'))
        ->assertInertia(fn (Assert $page) => $page->where('products.data.0.tracksExpiry', true));

    $this->actingAs($this->staff)->put(route('products.update', $this->susu), $payload(false))->assertSessionHasNoErrors();
    expect($this->susu->fresh())->tracks_expiry->toBeFalse()->stock_on_hand->toBe(6);
    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('shows batches on the stock card and filters movements by batch', function () {
    stockSusu($this);
    $soon = StockBatch::query()->where('code', 'L5')->sole();

    $this->actingAs($this->staff)->get(route('products.stock-card', $this->susu))
        ->assertInertia(fn (Assert $page) => $page
            ->where('product.tracksExpiry', true)
            ->has('batches', 3)
            ->where('batches.0.expiresOn', '2026-11-25')
            ->where('batches.0.status', 'expired')
            ->where('batches.1.status', 'soon')
            ->where('batches.1.label', '30 Nov 2026 · L5')
            ->where('batches.2.status', 'ok')
            ->where('movements.data.0.batch', '1 Feb 2027'));

    $this->actingAs($this->staff)->get(route('products.stock-card', [$this->susu, 'batch' => $soon->id]))
        ->assertInertia(fn (Assert $page) => $page->where('batchId', $soon->id)->has('movements.data', 1)->where('movements.data.0.qty', 3));
});

it('puts expiring and expired batches on the dashboard for owner and warehouse', function () {
    stockSusu($this);

    foreach ([$this->owner, $this->staff] as $user) {
        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expiry.warnDays', 7)
                ->where('expiry.expired.count', 1)
                ->where('expiry.expired.value', 10_000)
                ->where('expiry.soon.count', 1)
                ->has('expiry.items', 2)
                ->where('expiry.items.0.product', 'Susu UHT')
                ->where('expiry.items.0.days', -2)
                ->where('expiry.items.1.days', 3));
    }

    // Cashiers go straight to the till; with nothing tracked, the card stays away.
    $this->actingAs($this->cashier)->get(route('dashboard'))->assertRedirect(route('pos.index'));
    $this->ledger->stopTracking($this->susu);
    $this->actingAs($this->staff)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('expiry', null));
});

it('lists batches by status in the expiry report, for owner and warehouse only', function () {
    stockSusu($this);

    $this->actingAs($this->staff)->get(route('reports.expiry'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('reports/expiry')
            ->has('report.rows', 3)
            ->where('report.totals.expired.qty', 2)
            ->where('report.totals.expired.value', 10_000)
            ->where('report.totals.soon.qty', 3)
            ->where('report.totals.all.value', 50_000));

    $this->actingAs($this->staff)->get(route('reports.expiry', ['status' => 'soon']))
        ->assertInertia(fn (Assert $page) => $page->has('report.rows', 1)->where('report.rows.0.code', 'L5'));

    $this->actingAs($this->cashier)->get(route('reports.expiry'))->assertForbidden();
    $this->actingAs($this->cashier)->get(route('reports.export.expiry'))->assertForbidden();
});

it('exports the expiry report to Excel and PDF with the same rows', function () {
    stockSusu($this);
    $document = ReportTables::expiry();
    expect($document->filename)->toBe('laporan-kedaluwarsa-2026-11-27')->and($document->landscape)->toBeFalse();

    $response = $this->actingAs($this->staff)->get(route('reports.export.expiry'));
    $response->assertOk()->assertDownload('laporan-kedaluwarsa-2026-11-27.xlsx');

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

    expect(collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'SKU'))
        ->toBe(['SKU', 'Produk', 'Kode batch', 'Kedaluwarsa', 'Sisa', 'Satuan', 'HPP rata-rata', 'Nilai', 'Status']);
    $soon = collect($rows)->first(fn ($r) => ($r[2] ?? null) === 'L5');
    expect([$soon[0], $soon[1], $soon[4], $soon[5], $soon[7], $soon[8]])->toBe(['SUS-001', 'Susu UHT', 3, 'pcs', 15_000, 'Hampir kedaluwarsa'])
        ->and(collect($rows)->first(fn ($r) => ($r[1] ?? null) === 'Total')[7])->toBe(50_000);

    $this->actingAs($this->staff)->get(route('reports.pdf.expiry'))
        ->assertOk()->assertHeader('content-type', 'application/pdf')->assertDownload('laporan-kedaluwarsa-2026-11-27.pdf');

    expect(app(ReportPdf::class)->html($document, $this->staff))->toContain('L5')->toContain('Kedaluwarsa')->toContain('Rp 50.000');
});
