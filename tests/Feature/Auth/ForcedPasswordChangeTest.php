<?php

use App\Actions\Users\SetTemporaryPassword;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create()->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create()->assignRole(Role::Cashier->value);
});

it('ends the sessions that used the old password', function () {
    $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'password']);
    $this->get(route('pos.sales.index'))->assertOk();

    app(SetTemporaryPassword::class)->handle($this->owner, $this->cashier->fresh(), 'sementara-456');
    auth()->forgetGuards(); // the next request loads the user from the database, like a real one

    $this->get(route('pos.sales.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('sends the user to change the temporary password before anything else', function () {
    app(SetTemporaryPassword::class)->handle($this->owner, $this->cashier, 'sementara-456');

    $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'sementara-456']);

    $this->get(route('dashboard'))->assertRedirect(route('forced-password.edit'));
    $this->get(route('pos.index'))->assertRedirect(route('forced-password.edit'));
    $this->get(route('forced-password.edit'))->assertOk();

    $this->put(route('forced-password.update'), [
        'password' => 'sementara-456',
        'password_confirmation' => 'sementara-456',
    ])->assertSessionHasErrors('password');

    $this->put(route('forced-password.update'), [
        'password' => 'milik-andi-789',
        'password_confirmation' => 'milik-andi-789',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

    $andi = $this->cashier->fresh();
    expect($andi->must_change_password)->toBeFalse()
        ->and(Hash::check('milik-andi-789', $andi->password))->toBeTrue();
    $this->get(route('pos.sales.index'))->assertOk();
});

it('can still log out while a change is pending', function () {
    $this->cashier->forceFill(['must_change_password' => true])->save();

    $this->actingAs($this->cashier)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('sends someone with nothing to change away from the page', function () {
    $this->actingAs($this->cashier)->get(route('forced-password.edit'))->assertRedirect(route('dashboard'));
});
