<?php

use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ResetOwnerPin;
use App\Actions\Users\UpdateUser;
use App\Enums\Role;
use App\Models\AccountEvent;
use App\Models\User;
use App\Support\DemoAccounts;
use Database\Seeders\RoleSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;

/*
 * DESIGN.md §15: every change to an account — by an owner, by its user, or by the system —
 * leaves one immutable row saying who, what and when, and never a secret.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->create(['name' => 'Andi', 'email' => 'andi@toko.test'])->assignRole(Role::Cashier->value);
});

function auditPasskey(User $user, string $name = 'HP rahasia'): Passkey
{
    $passkey = new Passkey([
        'name' => $name,
        'credential_id' => 'cred-'.$user->id.'-'.uniqid(),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->forceFill(['user_id' => $user->id])->save();

    return $passkey;
}

/** @return array{type: string, user: int, actor: ?int, details: ?array<string, mixed>} */
function auditOnly(): array
{
    $event = AccountEvent::query()->sole();

    return [
        'type' => $event->type->value,
        'user' => $event->user_id,
        'actor' => $event->actor_id,
        'details' => $event->details,
    ];
}

describe('changes by an owner', function () {
    it('records a new account with its role', function () {
        $this->actingAs($this->owner)->post(route('users.store'), [
            'name' => 'Dewi', 'email' => 'dewi@toko.test', 'role' => 'kasir',
            'password' => 'rahasia-awal-123', 'password_confirmation' => 'rahasia-awal-123',
        ])->assertSessionHasNoErrors();

        $dewi = User::where('email', 'dewi@toko.test')->sole();
        expect(auditOnly())->toBe([
            'type' => 'created', 'user' => $dewi->id, 'actor' => $this->owner->id, 'details' => ['role' => 'kasir'],
        ]);
    });

    it('records only the fields that changed, old and new', function () {
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi Pratama', 'email' => 'andi@toko.test', 'role' => 'gudang',
        ])->assertSessionHasNoErrors();

        expect(auditOnly())->toBe([
            'type' => 'updated', 'user' => $this->cashier->id, 'actor' => $this->owner->id,
            'details' => ['name' => ['Andi', 'Andi Pratama'], 'role' => ['kasir', 'gudang']],
        ]);
    });

    it('records nothing when an edit changes nothing', function () {
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi', 'email' => 'andi@toko.test', 'role' => 'kasir',
        ])->assertSessionHasNoErrors();

        expect(AccountEvent::count())->toBe(0);
    });

    it('records deactivation, reactivation, password and PIN resets', function () {
        $rina = User::factory()->create(['pin_hash' => Hash::make('4321')])->assignRole(Role::Owner->value);

        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier));
        $this->actingAs($this->owner)->post(route('users.reactivate', $this->cashier));
        $this->actingAs($this->owner)->put(route('users.password', $this->cashier), [
            'password' => 'sementara-456', 'password_confirmation' => 'sementara-456',
        ]);
        $this->actingAs($this->owner)->delete(route('users.pin', $rina));

        expect(AccountEvent::query()->orderBy('id')->get()->map(fn (AccountEvent $e) => [$e->type->value, $e->user_id, $e->actor_id])->all())
            ->toBe([
                ['deactivated', $this->cashier->id, $this->owner->id],
                ['reactivated', $this->cashier->id, $this->owner->id],
                ['password_reset', $this->cashier->id, $this->owner->id],
                ['pin_reset', $rina->id, $this->owner->id],
            ]);
    });

    it('records a 2FA and passkey reset with what was there', function () {
        $this->cashier->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now()])->save();
        auditPasskey($this->cashier);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        expect(auditOnly())->toBe([
            'type' => 'sign_in_factors_reset', 'user' => $this->cashier->id, 'actor' => $this->owner->id,
            'details' => ['two_factor' => true, 'passkeys' => 1],
        ]);
    });

    it('records nothing for a change the guards refuse', function () {
        expect(fn () => app(DeactivateUser::class)->handle($this->owner, $this->owner))->toThrow(ValidationException::class)
            ->and(fn () => app(ResetOwnerPin::class)->handle($this->owner, $this->cashier))->toThrow(ValidationException::class)
            ->and(fn () => app(UpdateUser::class)->handle($this->owner, $this->owner, ['name' => 'Budi', 'email' => $this->owner->email, 'role' => Role::Cashier]))
            ->toThrow(ValidationException::class);

        expect(AccountEvent::count())->toBe(0);
    });
});

describe('changes by the user', function () {
    it('records a profile edit', function () {
        $this->actingAs($this->cashier)->patch(route('profile.update'), [
            'name' => 'Andi P', 'email' => 'andi.baru@toko.test',
        ])->assertSessionHasNoErrors();

        expect(auditOnly())->toBe([
            'type' => 'profile_updated', 'user' => $this->cashier->id, 'actor' => $this->cashier->id,
            'details' => ['name' => ['Andi', 'Andi P'], 'email' => ['andi@toko.test', 'andi.baru@toko.test']],
        ]);
    });

    it('records a password change from settings, the forced page and a reset link', function () {
        $this->actingAs($this->cashier)->put(route('user-password.update'), [
            'current_password' => 'password', 'password' => 'baru-sekali-123', 'password_confirmation' => 'baru-sekali-123',
        ])->assertSessionHasNoErrors();

        $this->cashier->fresh()->forceFill(['must_change_password' => true])->save();
        $this->actingAs($this->cashier->fresh())->put(route('forced-password.update'), [
            'password' => 'lain-lagi-456', 'password_confirmation' => 'lain-lagi-456',
        ])->assertSessionHasNoErrors();

        event(new PasswordReset($this->cashier->fresh()));

        expect(AccountEvent::query()->orderBy('id')->get()->map(fn (AccountEvent $e) => [$e->type->value, $e->actor_id, $e->details])->all())
            ->toBe([
                ['password_changed', $this->cashier->id, ['via' => 'settings']],
                ['password_changed', $this->cashier->id, ['via' => 'forced']],
                ['password_changed', $this->cashier->id, ['via' => 'reset_link']],
            ]);
    });

    it('records an owner setting their own PIN', function () {
        $this->actingAs($this->owner)->put(route('owner-pin.update'), [
            'current_password' => 'password', 'pin' => '987654', 'pin_confirmation' => '987654',
        ])->assertSessionHasNoErrors();

        expect(auditOnly())->toBe(['type' => 'pin_set', 'user' => $this->owner->id, 'actor' => $this->owner->id, 'details' => null]);
    });

    it('records 2FA turned on when confirmed, codes regenerated, and 2FA turned off', function () {
        // Enabling alone (before the code is confirmed) is not a change worth recording.
        event(new TwoFactorAuthenticationEnabled($this->cashier));
        expect(AccountEvent::count())->toBe(0);

        $this->cashier->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now()])->save();
        event(new TwoFactorAuthenticationConfirmed($this->cashier));
        event(new RecoveryCodesGenerated($this->cashier));

        $this->actingAs($this->cashier)->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))
            ->assertSessionHasNoErrors();

        expect(AccountEvent::query()->orderBy('id')->get()->map(fn (AccountEvent $e) => [$e->type->value, $e->user_id, $e->actor_id])->all())
            ->toBe([
                ['two_factor_enabled', $this->cashier->id, $this->cashier->id],
                ['recovery_codes_regenerated', $this->cashier->id, $this->cashier->id],
                ['two_factor_disabled', $this->cashier->id, $this->cashier->id],
            ]);
    });

    it('records a passkey added and removed without its name', function () {
        $passkey = auditPasskey($this->cashier);

        event(new PasskeyRegistered($this->cashier, $passkey));
        $this->actingAs($this->cashier)->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('passkey.destroy', $passkey))
            ->assertSessionHasNoErrors();

        expect(AccountEvent::query()->orderBy('id')->pluck('type')->map->value->all())
            ->toBe(['passkey_added', 'passkey_removed'])
            ->and(AccountEvent::query()->pluck('details')->filter()->all())->toBe([]);
    });

    it('takes the acting user from the request, not the account', function () {
        // A passkey event while an owner is logged in (no such route today) still names the owner.
        $passkey = auditPasskey($this->cashier);
        $this->actingAs($this->owner);

        event(new PasskeyDeleted($this->cashier, $passkey));

        expect(auditOnly()['actor'])->toBe($this->owner->id);
    });
});

describe('changes by the system', function () {
    it('records a demo account released at login only when something was cleared', function () {
        config(['pos.protect_demo_accounts' => true]);
        $demo = User::factory()->create(['email' => 'kasir@kasir.test'])->assignRole(Role::Cashier->value);

        DemoAccounts::releaseSignIn($demo);
        expect(AccountEvent::count())->toBe(0);

        $demo->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_confirmed_at' => now()])->save();
        auditPasskey($demo);
        auditPasskey($demo);
        DemoAccounts::releaseSignIn($demo->fresh());

        expect(auditOnly())->toBe([
            'type' => 'demo_sign_in_released', 'user' => $demo->id, 'actor' => null,
            'details' => ['two_factor' => true, 'passkeys' => 2],
        ]);
    });
});

describe('history page', function () {
    it('is for owners only', function (string $role) {
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)->get(route('users.events.index'))->assertForbidden();
    })->with([Role::Cashier->value, Role::Warehouse->value]);

    it('lists the newest first with who did it, filterable by account and type', function () {
        $this->actingAs($this->owner)->post(route('users.deactivate', $this->cashier));
        $this->actingAs($this->owner)->post(route('users.reactivate', $this->cashier));
        $this->actingAs($this->owner)->put(route('owner-pin.update'), [
            'current_password' => 'password', 'pin' => '987654', 'pin_confirmation' => '987654',
        ]);

        $this->actingAs($this->owner)->get(route('users.events.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('users/events')
                ->has('events.data', 3)
                ->where('events.data.0.type', 'pin_set')
                ->where('events.data.0.label', 'Mengatur PIN')
                ->where('events.data.0.user.name', 'Budi')
                ->where('events.data.0.by_self', true)
                ->where('events.data.2.type', 'deactivated')
                ->where('events.data.2.actor.name', 'Budi')
                ->where('events.data.2.by_self', false)
                ->has('types')
                ->has('users', 2));

        $this->actingAs($this->owner)->get(route('users.events.index', ['user' => $this->cashier->id]))
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 2));

        $this->actingAs($this->owner)->get(route('users.events.index', ['type' => 'reactivated']))
            ->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.type', 'reactivated'));
    });

    it('describes changes in words', function () {
        $this->actingAs($this->owner)->put(route('users.update', $this->cashier), [
            'name' => 'Andi', 'email' => 'andi@toko.test', 'role' => 'gudang',
        ]);

        $this->actingAs($this->owner)->get(route('users.events.index'))
            ->assertInertia(fn (Assert $page) => $page->where('events.data.0.changes', ['Role: Kasir → Staf Gudang']));
    });

    it('never stores or shows a secret', function () {
        $this->actingAs($this->owner)->put(route('users.password', $this->cashier), [
            'password' => 'sementara-456', 'password_confirmation' => 'sementara-456',
        ]);
        $this->actingAs($this->owner)->put(route('owner-pin.update'), [
            'current_password' => 'password', 'pin' => '987654', 'pin_confirmation' => '987654',
        ]);
        $passkey = auditPasskey($this->cashier, 'HP rahasia');
        event(new PasskeyRegistered($this->cashier, $passkey));

        $stored = json_encode(AccountEvent::query()->get()->toArray());
        $page = json_encode($this->actingAs($this->owner)->get(route('users.events.index'))->viewData('page'));

        foreach ([$stored, $page] as $json) {
            expect($json)->not->toContain('sementara-456')
                ->not->toContain('987654')
                ->not->toContain('HP rahasia')
                ->not->toContain('credential');
        }
    });
});
