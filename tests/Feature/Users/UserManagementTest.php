<?php

use App\Actions\Sales\OpenShift;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\UpdateUser;
use App\Enums\Role;
use App\Models\User;
use App\Services\Auth\OwnerPin;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
    $this->staff = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Warehouse->value);
});

function secondOwner(): User
{
    return User::factory()->create(['name' => 'Rina', 'pin_hash' => Hash::make('4321')])
        ->assignRole(Role::Owner->value);
}

describe('access', function () {
    it('is for owners only', function (string $who) {
        $user = $this->{$who};
        $target = $this->cashier;

        $this->actingAs($user)->get(route('users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('users.store'), [])->assertForbidden();
        $this->actingAs($user)->put(route('users.update', $target), [])->assertForbidden();
        $this->actingAs($user)->post(route('users.deactivate', $target))->assertForbidden();
        $this->actingAs($user)->post(route('users.reactivate', $target))->assertForbidden();
        $this->actingAs($user)->put(route('users.password', $target), [])->assertForbidden();
        $this->actingAs($user)->delete(route('users.pin', $target))->assertForbidden();
    })->with(['cashier', 'staff']);

    it('sends guests to the login page', function () {
        $this->get(route('users.index'))->assertRedirect(route('login'));
    });

    it('lists every account with its status', function () {
        $this->cashier->forceFill(['deactivated_at' => now()])->save();
        app(OpenShift::class)->handle($this->owner, 100000);

        $this->actingAs($this->owner)->get(route('users.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/index')
                ->has('users', 3)
                ->where('users.0.name', 'Andi')
                ->where('users.0.role', 'kasir')
                ->where('users.0.active', false)
                ->where('users.1.name', 'Budi')
                ->where('users.1.is_self', true)
                ->where('users.1.has_open_shift', true)
                ->where('users.1.has_pin', false)
                ->where('users.2.name', 'Siti'));
    });
});

describe('creating and editing', function () {
    it('creates a verified account that must change its password', function () {
        $this->actingAs($this->owner)->post(route('users.store'), [
            'name' => 'Dewi Lestari',
            'email' => 'Dewi@Kasir.test',
            'role' => 'kasir',
            'password' => 'rahasia-awal-123',
            'password_confirmation' => 'rahasia-awal-123',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $dewi = User::where('email', 'dewi@kasir.test')->sole();
        expect($dewi->hasRole(Role::Cashier->value))->toBeTrue()
            ->and($dewi->email_verified_at)->not->toBeNull()
            ->and($dewi->must_change_password)->toBeTrue()
            ->and($dewi->deactivated_at)->toBeNull()
            ->and(Hash::check('rahasia-awal-123', $dewi->password))->toBeTrue();
    });

    it('rejects a duplicate email, an unknown role and a short password', function () {
        $this->actingAs($this->owner)->post(route('users.store'), [
            'name' => 'Dobel',
            'email' => strtoupper($this->cashier->email),
            'role' => 'admin',
            'password' => 'abc',
            'password_confirmation' => 'abc',
        ])->assertSessionHasErrors(['email', 'role', 'password']);

        expect(User::count())->toBe(3);
    });

    it('changes name, email and role', function () {
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi P.',
            'email' => 'andi.baru@kasir.test',
            'role' => 'gudang',
        ])->assertSessionHasNoErrors();

        $andi = $this->cashier->fresh();
        expect($andi->name)->toBe('Andi P.')
            ->and($andi->email)->toBe('andi.baru@kasir.test')
            ->and($andi->email_verified_at)->not->toBeNull()
            ->and($andi->getRoleNames()->all())->toBe(['gudang']);
    });

    it('clears the PIN when an owner stops being an owner', function () {
        $rina = secondOwner();

        $this->actingAs($this->owner)->put(route('users.update', $rina), [
            'name' => 'Rina', 'email' => $rina->email, 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        expect($rina->fresh()->pin_hash)->toBeNull();
    });
});

describe('guards', function () {
    it('does not let an owner deactivate or demote themself', function () {
        secondOwner();

        $this->actingAs($this->owner)->post(route('users.deactivate', $this->owner))
            ->assertSessionHasErrors('user');
        $this->actingAs($this->owner)->put(route('users.update', $this->owner), [
            'name' => 'Budi', 'email' => $this->owner->email, 'role' => 'kasir',
        ])->assertSessionHasErrors('role');

        $owner = $this->owner->fresh();
        expect($owner->deactivated_at)->toBeNull()
            ->and($owner->hasRole(Role::Owner->value))->toBeTrue();
    });

    it('still lets an owner rename themself', function () {
        $this->actingAs($this->owner)->put(route('users.update', $this->owner), [
            'name' => 'Budi S.', 'email' => $this->owner->email, 'role' => 'owner',
        ])->assertSessionHasNoErrors();

        expect($this->owner->fresh()->name)->toBe('Budi S.');
    });

    it('keeps at least one active owner', function () {
        $rina = secondOwner();
        $this->owner->forceFill(['deactivated_at' => now()])->save();

        // Only Rina is an active owner now; the action is what guards it, not the UI.
        expect(fn () => app(DeactivateUser::class)->handle($this->cashier, $rina))
            ->toThrow(ValidationException::class, 'Harus ada minimal satu owner aktif.');
        expect(fn () => app(UpdateUser::class)->handle($this->cashier, $rina, [
            'name' => 'Rina', 'email' => $rina->email, 'role' => Role::Cashier,
        ]))->toThrow(ValidationException::class, 'Harus ada minimal satu owner aktif.');

        expect($rina->fresh()->deactivated_at)->toBeNull();
    });

    it('lets one owner deactivate another while one stays active', function () {
        $rina = secondOwner();

        $this->actingAs($this->owner)->post(route('users.deactivate', $rina))->assertSessionHasNoErrors();

        expect($rina->fresh()->deactivated_at)->not->toBeNull();
    });

    it('refuses to deactivate an account with an open shift, or make it warehouse staff', function () {
        app(OpenShift::class)->handle($this->cashier, 100000);

        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier))
            ->assertSessionHasErrors(['user' => 'Andi masih punya shift terbuka. Tutup shift dulu.']);
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi', 'email' => $this->cashier->email, 'role' => 'gudang',
        ])->assertSessionHasErrors('role');

        // Promoting to owner keeps till access, so it is allowed.
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi', 'email' => $this->cashier->email, 'role' => 'owner',
        ])->assertSessionHasNoErrors();

        expect($this->cashier->fresh()->deactivated_at)->toBeNull();
    });
});

describe('deactivating', function () {
    it('blocks password login with a clear message', function () {
        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier));
        auth()->logout();

        $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Akun ini dinonaktifkan. Hubungi owner.']);
        $this->assertGuest();
    });

    it('still rejects a wrong password with the usual message', function () {
        $this->cashier->forceFill(['deactivated_at' => now()])->save();

        $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'salah'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    });

    it('ends a session that is already logged in', function () {
        $this->actingAs($this->cashier)->get(route('pos.sales.index'))->assertOk();

        $this->cashier->forceFill(['deactivated_at' => now()])->save();

        $this->get(route('pos.sales.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Akun ini dinonaktifkan. Hubungi owner.']);
        $this->assertGuest();
    });

    it('rotates the remember token', function () {
        $before = $this->cashier->remember_token;

        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier));

        expect($this->cashier->fresh()->remember_token)->not->toBe($before);
    });

    it('can be undone', function () {
        $this->cashier->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->owner)->post(route('users.reactivate', $this->cashier))->assertSessionHasNoErrors();
        auth()->logout();

        $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($this->cashier->fresh());
    });

    it('stops a deactivated owner approving returns by PIN', function () {
        $rina = secondOwner();
        $this->actingAs($this->owner)->post(route('users.deactivate', $rina));

        expect(fn () => app(OwnerPin::class)->approve($this->cashier, '4321'))
            ->toThrow(ValidationException::class);
    });
});

describe('resetting', function () {
    it('sets a temporary password that must be changed at the next login', function () {
        $this->actingAs($this->owner)->put(route('users.password', $this->cashier), [
            'password' => 'sementara-456',
            'password_confirmation' => 'sementara-456',
        ])->assertSessionHasNoErrors();

        $andi = $this->cashier->fresh();
        expect(Hash::check('sementara-456', $andi->password))->toBeTrue()
            ->and($andi->must_change_password)->toBeTrue();
    });

    it('does not reset its own password here', function () {
        $this->actingAs($this->owner)->put(route('users.password', $this->owner), [
            'password' => 'sementara-456',
            'password_confirmation' => 'sementara-456',
        ])->assertSessionHasErrors('user');
    });

    it("clears another owner's PIN", function () {
        $rina = secondOwner();

        $this->actingAs($this->owner)->delete(route('users.pin', $rina))->assertSessionHasNoErrors();

        expect($rina->fresh()->pin_hash)->toBeNull();
    });

    it('does not clear its own PIN or a non-owner PIN', function () {
        $this->owner->forceFill(['pin_hash' => Hash::make('1234')])->save();

        $this->actingAs($this->owner)->delete(route('users.pin', $this->owner))->assertSessionHasErrors('user');
        $this->actingAs($this->owner)->delete(route('users.pin', $this->cashier))->assertSessionHasErrors('user');

        expect($this->owner->fresh()->pin_hash)->not->toBeNull();
    });
});

describe('protected demo accounts', function () {
    beforeEach(function () {
        config(['pos.protect_demo_accounts' => true]);
        $this->demoCashier = User::factory()->create(['email' => 'kasir@kasir.test'])->assignRole(Role::Cashier->value);
        $this->demoOwner = User::factory()->create(['email' => 'owner@kasir.test', 'pin_hash' => Hash::make('1234')])
            ->assignRole(Role::Owner->value);
    });

    it('cannot be deactivated, re-roled, renamed by email or reset', function () {
        $this->actingAs($this->owner)->post(route('users.deactivate', $this->demoCashier))->assertSessionHasErrors('user');
        $this->actingAs($this->owner)->put(route('users.update', $this->demoCashier), [
            'name' => 'Iseng', 'email' => 'iseng@kasir.test', 'role' => 'gudang',
        ])->assertSessionHasErrors('user');
        $this->actingAs($this->owner)->put(route('users.password', $this->demoCashier), [
            'password' => 'sementara-456', 'password_confirmation' => 'sementara-456',
        ])->assertSessionHasErrors('user');
        $this->actingAs($this->owner)->delete(route('users.pin', $this->demoOwner))->assertSessionHasErrors('user');

        $demo = $this->demoCashier->fresh();
        expect($demo->deactivated_at)->toBeNull()
            ->and($demo->email)->toBe('kasir@kasir.test')
            ->and($demo->hasRole(Role::Cashier->value))->toBeTrue()
            ->and(Hash::check('password', $demo->password))->toBeTrue()
            ->and($this->demoOwner->fresh()->pin_hash)->not->toBeNull();
    });

    it('cannot change its own password, email or PIN in settings', function () {
        $this->actingAs($this->demoOwner->fresh())->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'baru-sekali-789', 'password_confirmation' => 'baru-sekali-789',
        ])->assertSessionHasErrors('password');
        $this->actingAs($this->demoOwner->fresh())->patch(route('profile.update'), [
            'name' => 'Budi', 'email' => 'lain@kasir.test',
        ])->assertSessionHasErrors('email');
        $this->actingAs($this->demoOwner->fresh())->put(route('owner-pin.update'), [
            'current_password' => 'password', 'pin' => '9999', 'pin_confirmation' => '9999',
        ])->assertSessionHasErrors('pin');

        $owner = $this->demoOwner->fresh();
        expect(Hash::check('password', $owner->password))->toBeTrue()
            ->and($owner->email)->toBe('owner@kasir.test')
            ->and(Hash::check('1234', (string) $owner->pin_hash))->toBeTrue();
    });

    it('leaves other accounts fully manageable', function () {
        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier))->assertSessionHasNoErrors();

        expect($this->cashier->fresh()->deactivated_at)->not->toBeNull();
    });

    it('is off by default', function () {
        config(['pos.protect_demo_accounts' => false]);

        $this->actingAs($this->owner)->post(route('users.deactivate', $this->demoCashier))->assertSessionHasNoErrors();
    });
});
