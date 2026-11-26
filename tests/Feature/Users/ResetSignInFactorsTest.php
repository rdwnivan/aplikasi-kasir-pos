<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;

/*
 * DESIGN.md §14: an owner clears another account's 2FA and passkeys (lost or stolen phone),
 * confirming with their own password, so that account signs in with its password again.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->owner = User::factory()->create(['name' => 'Budi'])->assignRole(Role::Owner->value);
    $this->cashier = User::factory()->withTwoFactor()->create(['name' => 'Andi'])->assignRole(Role::Cashier->value);
});

function resetFactorsPasskey(User $user, string $name = 'Ponsel'): Passkey
{
    $passkey = new Passkey([
        'name' => $name,
        'credential_id' => 'cred-'.$user->id.'-'.uniqid(),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->forceFill(['user_id' => $user->id])->save();

    return $passkey;
}

/** @return array{secret: ?string, codes: ?string, confirmed: ?string, passkeys: int} */
function resetFactorsState(User $user): array
{
    $user = $user->fresh();

    return [
        'secret' => $user->two_factor_secret,
        'codes' => $user->two_factor_recovery_codes,
        'confirmed' => $user->two_factor_confirmed_at?->toIso8601String(),
        'passkeys' => Passkey::query()->where('user_id', $user->id)->count(),
    ];
}

function resetFactorsEmpty(): array
{
    return ['secret' => null, 'codes' => null, 'confirmed' => null, 'passkeys' => 0];
}

describe('access', function () {
    it('is for owners only', function (string $role) {
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password'])
            ->assertForbidden();

        expect(resetFactorsState($this->cashier)['secret'])->not->toBeNull();
    })->with([Role::Cashier->value, Role::Warehouse->value]);

    it('sends guests to the login page', function () {
        $this->delete(route('users.sign-in-factors', $this->cashier))->assertRedirect(route('login'));
    });
});

describe('resetting', function () {
    it('clears 2FA and every passkey of that account only', function () {
        resetFactorsPasskey($this->cashier, 'HP lama');
        resetFactorsPasskey($this->cashier, 'Laptop');
        $other = User::factory()->withTwoFactor()->create()->assignRole(Role::Cashier->value);
        resetFactorsPasskey($other);
        $token = $this->cashier->fresh()->getRememberToken();

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(resetFactorsState($this->cashier))->toBe(resetFactorsEmpty())
            ->and($this->cashier->fresh()->getRememberToken())->not->toBe($token)
            ->and(resetFactorsState($other)['passkeys'])->toBe(1)
            ->and(resetFactorsState($other)['secret'])->not->toBeNull();
    });

    it('lets the account sign in with its password alone afterwards', function () {
        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password']);
        auth()->logout();

        $this->post(route('login.store'), ['email' => $this->cashier->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->cashier->fresh());
    });

    it('clears a 2FA setup that was never confirmed', function () {
        $half = User::factory()->create([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        ])->assignRole(Role::Cashier->value);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $half), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        expect(resetFactorsState($half))->toBe(resetFactorsEmpty());
    });

    it('clears passkeys of an account without 2FA', function () {
        $staff = User::factory()->create()->assignRole(Role::Warehouse->value);
        resetFactorsPasskey($staff);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $staff), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        expect(resetFactorsState($staff)['passkeys'])->toBe(0);
    });

    it('works for another owner and for an inactive account', function () {
        $rina = User::factory()->withTwoFactor()->create()->assignRole(Role::Owner->value);
        $this->cashier->forceFill(['deactivated_at' => now()])->save();

        foreach ([$rina, $this->cashier] as $target) {
            $this->actingAs($this->owner)
                ->delete(route('users.sign-in-factors', $target), ['password' => 'password'])
                ->assertSessionHasNoErrors();

            expect(resetFactorsState($target))->toBe(resetFactorsEmpty());
        }
    });

    it('logs who reset whom, without secrets', function () {
        Log::spy();
        resetFactorsPasskey($this->cashier);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password']);

        Log::shouldHaveReceived('notice')->once()->withArgs(fn (string $message, array $context) => $context === [
            'actor_id' => $this->owner->id,
            'user_id' => $this->cashier->id,
            'had_two_factor' => true,
            'passkeys' => 1,
        ]);
    });
});

describe('guards', function () {
    it('needs the owner\'s own password', function (?string $password) {
        $before = resetFactorsState($this->cashier);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => $password])
            ->assertSessionHasErrors('password');

        expect(resetFactorsState($this->cashier))->toBe($before);
    })->with(['wrong' => 'salah-sekali', 'empty' => null]);

    it('refuses the owner\'s own account', function () {
        $owner = User::factory()->withTwoFactor()->create()->assignRole(Role::Owner->value);

        $this->actingAs($owner)
            ->delete(route('users.sign-in-factors', $owner), ['password' => 'password'])
            ->assertSessionHasErrors(['user' => 'Atur 2FA dan passkey Anda sendiri di Pengaturan → Keamanan.']);

        expect(resetFactorsState($owner)['secret'])->not->toBeNull();
    });

    it('refuses an account with nothing to reset', function () {
        $staff = User::factory()->create(['name' => 'Siti'])->assignRole(Role::Warehouse->value);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $staff), ['password' => 'password'])
            ->assertSessionHasErrors(['user' => 'Siti tidak memakai 2FA maupun passkey.']);
    });

    it('refuses a protected demo account but not a visitor\'s own account', function () {
        config(['pos.protect_demo_accounts' => true]);
        $demo = User::factory()->withTwoFactor()->create(['email' => 'kasir@kasir.test'])->assignRole(Role::Cashier->value);

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $demo), ['password' => 'password'])
            ->assertSessionHasErrors('user');
        expect(resetFactorsState($demo)['secret'])->not->toBeNull();

        $this->actingAs($this->owner)
            ->delete(route('users.sign-in-factors', $this->cashier), ['password' => 'password'])
            ->assertSessionHasNoErrors();
        expect(resetFactorsState($this->cashier))->toBe(resetFactorsEmpty());
    });
});

describe('users page', function () {
    it('shows whether each account uses 2FA and how many passkeys, and nothing secret', function () {
        resetFactorsPasskey($this->cashier);
        resetFactorsPasskey($this->cashier);

        $response = $this->actingAs($this->owner)->get(route('users.index'));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('users.0.name', 'Andi')
            ->where('users.0.two_factor', true)
            ->where('users.0.passkeys', 2)
            ->where('users.1.name', 'Budi')
            ->where('users.1.two_factor', false)
            ->where('users.1.passkeys', 0));

        $json = json_encode($response->viewData('page'));
        expect($json)->not->toContain('two_factor_secret')
            ->not->toContain('two_factor_recovery_codes')
            ->not->toContain('credential')
            ->not->toContain('Ponsel');
    });
});
