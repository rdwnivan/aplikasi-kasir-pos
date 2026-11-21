<?php

use App\Actions\Inventory\ReceiveGoods;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
});

it('manages categories; deleting one leaves its products uncategorised', function () {
    $this->actingAs($this->owner)->post(route('categories.store'), ['name' => '  Minuman '])->assertSessionHasNoErrors();
    $category = Category::sole();
    expect($category->name)->toBe('Minuman');

    $this->actingAs($this->owner)->post(route('categories.store'), ['name' => 'Minuman'])->assertSessionHasErrors('name');

    $this->actingAs($this->owner)->put(route('categories.update', $category), ['name' => 'Minuman Dingin'])->assertSessionHasNoErrors();
    $product = Product::factory()->create(['category_id' => $category->id]);

    $this->actingAs($this->owner)->get(route('categories.index'))
        ->assertInertia(fn (Assert $page) => $page->component('catalog/categories')->where('items.0.name', 'Minuman Dingin')->where('items.0.usage', 1));

    $this->actingAs($this->owner)->delete(route('categories.destroy', $category))->assertSessionHasNoErrors();
    expect($product->fresh()->category_id)->toBeNull();
});

it('manages units and refuses to delete one in use', function () {
    $this->actingAs($this->owner)->post(route('units.store'), ['name' => 'DUS'])->assertSessionHasNoErrors();
    $dus = Unit::sole();
    expect($dus->name)->toBe('dus');

    $product = Product::factory()->create();
    ProductUnit::factory()->for($product)->create(['unit_id' => $dus->id, 'conversion' => 24]);

    $this->actingAs($this->owner)->delete(route('units.destroy', $dus))->assertSessionHasErrors('unit');
    expect(Unit::whereKey($dus->id)->exists())->toBeTrue();

    $spare = Unit::factory()->create(['name' => 'lusin']);
    $this->actingAs($this->owner)->delete(route('units.destroy', $spare))->assertSessionHasNoErrors();
    expect(Unit::whereKey($spare->id)->exists())->toBeFalse();
});

it('manages suppliers and keeps one that appears on a goods receipt', function () {
    $this->actingAs($this->owner)->post(route('suppliers.store'), [
        'name' => 'CV Sumber Rejeki', 'phone' => '0812', 'address' => 'Bandung',
    ])->assertSessionHasNoErrors();
    $supplier = Supplier::sole();

    $this->actingAs($this->owner)->put(route('suppliers.update', $supplier), ['name' => 'CV Sumber Rejeki Grosir', 'phone' => null, 'address' => null])
        ->assertSessionHasNoErrors();
    expect($supplier->fresh())->name->toBe('CV Sumber Rejeki Grosir')->phone->toBeNull();

    $pcs = Unit::factory()->create();
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id]);
    app(ReceiveGoods::class)->handle($this->owner, [['product_id' => $product->id, 'unit_id' => $pcs->id, 'qty' => 1, 'unit_price' => 1000]], $supplier->id);

    $this->actingAs($this->owner)->delete(route('suppliers.destroy', $supplier))->assertSessionHasErrors('supplier');

    $unused = Supplier::factory()->create();
    $this->actingAs($this->owner)->delete(route('suppliers.destroy', $unused))->assertSessionHasNoErrors();
    expect(Supplier::count())->toBe(1);
});

it('speaks Indonesian in validation messages', function () {
    $this->actingAs($this->owner)->post(route('units.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Nama satuan wajib diisi.']);
});

it('answers an Inertia XHR with a redirect and session errors, not JSON', function () {
    $supplier = Supplier::factory()->create();
    $pcs = Unit::factory()->create();
    $product = Product::factory()->create(['base_unit_id' => $pcs->id]);
    ProductUnit::factory()->for($product)->create(['unit_id' => $pcs->id]);
    app(ReceiveGoods::class)->handle($this->owner, [['product_id' => $product->id, 'unit_id' => $pcs->id, 'qty' => 1, 'unit_price' => 1000]], $supplier->id);

    $this->actingAs($this->owner)
        ->from(route('suppliers.index'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->delete(route('suppliers.destroy', $supplier))
        ->assertRedirect(route('suppliers.index'))
        ->assertSessionHasErrors('supplier');
});
