<?php

use App\Actions\Inventory\CreateStockOpname;
use App\Actions\Inventory\PostStockOpname;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\Inventory\StockLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Stock opname per batch for products that track expiry (DESIGN.md §19 P6).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-27 10:00:00');
    $this->seed(RoleSeeder::class);
    $this->staff = User::factory()->create()->assignRole(Role::Warehouse->value);
    $this->ledger = app(StockLedger::class);

    $this->susu = Product::factory()->create(['name' => 'Susu UHT']);
    $this->ledger->receive($this->susu, 4, '5000'); // old stock → batch without a date
    $this->ledger->startTracking($this->susu);
    $this->ledger->receive($this->susu, 6, '5000', batches: [['expires_on' => '2026-12-10', 'qty' => 6]]);
    $this->undated = StockBatch::query()->whereNull('expires_on')->sole();
    $this->dated = StockBatch::query()->whereNotNull('expires_on')->sole();

    $this->gula = Product::factory()->create(['name' => 'Gula']);
    $this->ledger->receive($this->gula, 10, '14000');
});

it('counts batch by batch, dates old stock through a found batch, and keeps HPP', function () {
    $opname = app(CreateStockOpname::class)->handle($this->staff, [$this->susu->id => 0, $this->gula->id => 9], batches: [
        $this->susu->id => [
            ['stock_batch_id' => $this->undated->id, 'counted_qty' => 0],
            ['stock_batch_id' => $this->dated->id, 'counted_qty' => 5],
            ['expires_on' => '2027-01-20', 'code' => 'X9', 'counted_qty' => 4],
        ],
    ]);

    $item = $opname->items->firstWhere('product_id', $this->susu->id);
    expect($item->counted_qty)->toBe(9); // the sum of the batches, whatever was sent

    app(PostStockOpname::class)->handle($opname, $this->staff);

    expect($this->undated->fresh()->qty_on_hand)->toBe(0)
        ->and($this->dated->fresh()->qty_on_hand)->toBe(5)
        ->and(StockBatch::query()->where('code', 'X9')->sole()->qty_on_hand)->toBe(4)
        ->and($this->susu->fresh())->stock_on_hand->toBe(9)->avg_cost->toBe('5000.0000')
        ->and($item->fresh())->system_qty->toBe(10)->difference->toBe(-1)
        ->and($this->gula->fresh()->stock_on_hand)->toBe(9)
        ->and(StockMovement::query()->where('type', StockMovementType::Opname)->where('product_id', $this->susu->id)->pluck('qty')->all())
        ->toBe([-4, -1, 4]);

    $lines = $item->batches()->orderBy('id')->get();
    expect($lines->map(fn ($l) => [$l->system_qty, $l->counted_qty, $l->difference])->all())->toBe([[4, 0, -4], [6, 5, -1], [0, 4, 4]]);

    $this->artisan('stock:rebuild')->assertSuccessful();
});

it('refuses counts that do not fit the product', function (string $case, string $message) {
    $batches = match ($case) {
        'tracked without batches' => [],
        'untracked with batches' => [$this->susu->id => [['stock_batch_id' => $this->dated->id, 'counted_qty' => 6]], $this->gula->id => [['expires_on' => '2027-01-01', 'counted_qty' => 1]]],
        'someone else\'s batch' => [$this->susu->id => [['stock_batch_id' => 999_999, 'counted_qty' => 1]]],
        'found batch without a date' => [$this->susu->id => [['counted_qty' => 1]]],
    };

    expect(fn () => app(CreateStockOpname::class)->handle($this->staff, [$this->susu->id => 0, $this->gula->id => 10], batches: $batches))
        ->toThrow(ValidationException::class, $message)
        ->and(StockOpname::query()->count())->toBe(0);
})->with([
    ['tracked without batches', 'Hitung Susu UHT per tanggal kedaluwarsa.'],
    ['untracked with batches', 'Gula tidak melacak tanggal kedaluwarsa.'],
    ['someone else\'s batch', 'Batch ini bukan milik Susu UHT.'],
    ['found batch without a date', 'Batch baru butuh tanggal kedaluwarsa.'],
]);

it('takes batch counts over HTTP and shows them', function () {
    $this->actingAs($this->staff)->get(route('stock-opnames.create'))
        ->assertInertia(fn (Assert $page) => $page->where('products', fn ($products) => collect($products)->firstWhere('name', 'Susu UHT')['batches'] === [
            ['id' => $this->undated->id, 'expiresOn' => null, 'code' => null, 'qty' => 4, 'status' => 'undated'],
            ['id' => $this->dated->id, 'expiresOn' => '2026-12-10', 'code' => null, 'qty' => 6, 'status' => 'soon'],
        ]));

    $this->actingAs($this->staff)->post(route('stock-opnames.store'), [
        'items' => [
            ['product_id' => $this->susu->id, 'counted_qty' => 0, 'batches' => [
                ['stock_batch_id' => $this->undated->id, 'counted_qty' => 3],
                ['stock_batch_id' => $this->dated->id, 'counted_qty' => 6],
            ]],
        ],
    ])->assertSessionHasNoErrors();

    $opname = StockOpname::query()->sole();

    $this->actingAs($this->staff)->get(route('stock-opnames.show', $opname))
        ->assertInertia(fn (Assert $page) => $page
            ->where('opname.items.0.countedQty', 9)
            ->where('opname.items.0.batches.0.label', 'Tanpa tanggal')
            ->where('opname.items.0.batches.0.systemQty', 4)
            ->where('opname.items.0.batches.0.difference', -1));

    $this->actingAs($this->staff)->get(route('stock-opnames.edit', $opname))
        ->assertInertia(fn (Assert $page) => $page->where("opname.batchCounts.{$this->susu->id}.0.stock_batch_id", $this->undated->id));
});
