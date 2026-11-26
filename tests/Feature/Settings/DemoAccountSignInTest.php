<?php

use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
 * DESIGN.md §12.4.1: on the public demo nobody may change how a protected demo account signs
 * in (2FA, passkeys), and a lock a visitor left behind is cleared at the next password login.
 */

function passkeyFor(User $user): Passkey
{
    $passkey = new Passkey([
        'name' => 'Ponsel pengunjung',
        'credential_id' => 'cred-'.$user->id.'-'.uniqid(),
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->forceFill(['user_id' => $user->id])->save();

    return $passkey;
}

/** @return array{secret: ?string, codes: ?string, confirmed: mixed, passkeys: int} */
function signInState(User $user): array
{
    $user = $user->fresh();

    return [
        'secret' => $user->two_factor_secret,
        'codes' => $user->two_factor_recovery_codes,
        'confirmed' => $user->two_factor_confirmed_at?->toIso8601String(),
        'passkeys' => Passkey::query()->where('user_id', $user->id)->count(),
    ];
}

/**
 * Sends one of the guarded requests the way the app's own UI does: Inertia forms for 2FA and
 * passkey deletion, JSON fetches (`@laravel/passkeys`) for registration.
 */
function sendSignInRequest(TestCase $test, User $user, string $route, ?Passkey $passkey = null, bool $confirmed = true): TestResponse
{
    $test->actingAs($user);
    if ($confirmed) {
        $test->withSession(['auth.password_confirmed_at' => time()]);
    }

    return match ($route) {
        'two-factor.enable' => $test->post(route($route)),
        'two-factor.confirm' => $test->post(route($route), ['code' => '123456']),
        'two-factor.regenerate-recovery-codes' => $test->post(route($route)),
        'two-factor.disable' => $test->delete(route($route)),
        'passkey.registration-options' => $test->getJson(route($route)),
        'passkey.store' => $test->postJson(route($route), ['name' => 'Kunci', 'credential' => ['id' => 'x']]),
        'passkey.destroy' => $test->delete(route($route, $passkey)),
    };
}

dataset('sign-in routes', [
    'two-factor.enable',
    'two-factor.confirm',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
    'passkey.registration-options',
    'passkey.store',
    'passkey.destroy',
]);

describe('protected demo account', function () {
    beforeEach(function () {
        config(['pos.protect_demo_accounts' => true]);
    });

    it('rejects every 2FA and passkey change and leaves them as they were', function (string $route) {
        // A lock a visitor left before this change: it must not be added to, nor removed here.
        $demo = User::factory()->withTwoFactor()->create(['email' => 'owner@kasir.test']);
        $passkey = passkeyFor($demo);
        $before = signInState($demo);

        $response = sendSignInRequest($this, $demo, $route, $passkey);

        if (in_array($route, ['passkey.registration-options', 'passkey.store'], true)) {
            $response->assertForbidden()->assertJson(['message' => DemoAccounts::MESSAGE]);
        } else {
            $response->assertRedirect()->assertSessionHasErrors([
                str_starts_with($route, 'two-factor') ? 'two_factor' : 'passkey' => DemoAccounts::MESSAGE,
            ]);
        }
        expect(signInState($demo))->toBe($before);
    })->with('sign-in routes');

    it('cannot turn on 2FA from a clean state', function () {
        $demo = User::factory()->create(['email' => 'kasir@kasir.test']);

        sendSignInRequest($this, $demo, 'two-factor.enable')->assertSessionHasErrors('two_factor');

        expect($demo->fresh()->two_factor_secret)->toBeNull();
    });

    it('is rejected without a password confirmation instead of being sent to confirm it', function () {
        $demo = User::factory()->create(['email' => 'gudang@kasir.test']);

        $response = sendSignInRequest($this, $demo, 'two-factor.enable', confirmed: false);

        $response->assertSessionHasErrors('two_factor');
        expect($response->headers->get('Location'))->not->toBe(route('password.confirm'));
    });

    it('locks the 2FA and passkey sections on the security page', function () {
        $demo = User::factory()->create(['email' => 'owner@kasir.test']);

        $this->actingAs($demo)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('signInLocked', true));
    });

    it('drops a 2FA and passkey a visitor left behind at the next password login', function () {
        $demo = User::factory()->withTwoFactor()->create(['email' => 'owner@kasir.test']);
        passkeyFor($demo);

        $this->post(route('login.store'), ['email' => 'owner@kasir.test', 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($demo);
        expect(signInState($demo))->toBe(['secret' => null, 'codes' => null, 'confirmed' => null, 'passkeys' => 0]);
    });

    it('clears nothing on a wrong password', function () {
        $demo = User::factory()->withTwoFactor()->create(['email' => 'owner@kasir.test']);
        passkeyFor($demo);
        $before = signInState($demo);

        $this->post(route('login.store'), ['email' => 'owner@kasir.test', 'password' => 'salah'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        expect(signInState($demo))->toBe($before);
    });
});

describe('other accounts on the public demo', function () {
    beforeEach(function () {
        config(['pos.protect_demo_accounts' => true]);
        $this->user = User::factory()->create(['email' => 'pengunjung@contoh.test']);
    });

    it('can turn 2FA on, confirm it, regenerate codes and turn it off', function () {
        sendSignInRequest($this, $this->user, 'two-factor.enable')->assertSessionHasNoErrors();
        $secret = decrypt((string) $this->user->fresh()->two_factor_secret);

        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasNoErrors();
        expect($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();

        $codes = $this->user->fresh()->two_factor_recovery_codes;
        $this->post(route('two-factor.regenerate-recovery-codes'))->assertSessionHasNoErrors();
        expect($this->user->fresh()->two_factor_recovery_codes)->not->toBe($codes);

        $this->delete(route('two-factor.disable'))->assertSessionHasNoErrors();
        expect($this->user->fresh()->two_factor_secret)->toBeNull();
    });

    it('can ask for passkey options, reach registration, and delete its passkey', function () {
        sendSignInRequest($this, $this->user, 'passkey.registration-options')->assertOk();

        // A real WebAuthn credential cannot be made in a test: the vendor rejects this fake one,
        // but not with the demo message.
        $store = sendSignInRequest($this, $this->user, 'passkey.store');
        expect($store->status())->not->toBe(403)
            ->and($store->json('message'))->not->toBe(DemoAccounts::MESSAGE);

        $passkey = passkeyFor($this->user);
        sendSignInRequest($this, $this->user, 'passkey.destroy', $passkey)->assertSessionHasNoErrors();
        expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeFalse();
    });

    it('keeps its 2FA at login and gets the challenge', function () {
        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        expect($user->fresh()->two_factor_secret)->not->toBeNull();
    });

    it('has an unlocked security page', function () {
        $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('signInLocked', false));
    });
});

describe('protection turned off (local default)', function () {
    beforeEach(function () {
        config(['pos.protect_demo_accounts' => false]);
        $this->demo = User::factory()->create(['email' => 'owner@kasir.test']);
    });

    it('lets the demo accounts manage 2FA and passkeys', function () {
        sendSignInRequest($this, $this->demo, 'two-factor.enable')->assertSessionHasNoErrors();
        expect($this->demo->fresh()->two_factor_secret)->not->toBeNull();

        sendSignInRequest($this, $this->demo, 'passkey.registration-options')->assertOk();

        $passkey = passkeyFor($this->demo);
        sendSignInRequest($this, $this->demo, 'passkey.destroy', $passkey)->assertSessionHasNoErrors();
        expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeFalse();
    });

    it('keeps a demo account 2FA and passkeys at login', function () {
        $this->demo->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        passkeyFor($this->demo);

        $this->post(route('login.store'), ['email' => 'owner@kasir.test', 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));

        expect(signInState($this->demo)['secret'])->not->toBeNull()
            ->and(signInState($this->demo)['passkeys'])->toBe(1);
    });

    it('has an unlocked security page', function () {
        $this->actingAs($this->demo)->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('signInLocked', false));
    });
});
