<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetOwnerPin;
use App\Actions\Users\ResetSignInFactors;
use App\Actions\Users\SetTemporaryPassword;
use App\Actions\Users\UpdateUser;
use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserRequest;
use App\Models\Shift;
use App\Models\User;
use App\Support\DemoAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Pengguna: owners manage accounts. The rules live in the Actions (App\Actions\Users). */
class UserController extends Controller
{
    use PasswordValidationRules;

    public function index(Request $request): Response
    {
        $openShifts = Shift::query()->open()->pluck('id', 'user_id');

        return Inertia::render('users/index', [
            'users' => User::query()->with('roles')->withCount('passkeys')->orderBy('name')->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first(),
                    'active' => $user->isActive(),
                    'deactivated_at' => $user->deactivated_at?->toIso8601String(),
                    'has_pin' => $user->pin_hash !== null,
                    // Whether 2FA / passkeys are in use, never the secrets or the devices.
                    'two_factor' => $user->hasEnabledTwoFactorAuthentication(),
                    'has_two_factor_state' => $user->hasTwoFactorState(),
                    'passkeys' => $user->passkeys_count,
                    'must_change_password' => $user->must_change_password,
                    'has_open_shift' => $openShifts->has($user->id),
                    'open_shift_id' => $openShifts->get($user->id),
                    'is_self' => $user->is($request->user()),
                    'is_protected' => DemoAccounts::isProtected($user),
                ]),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(UserRequest $request, CreateUser $create): RedirectResponse
    {
        $user = $create->handle($request->user(), [...$request->account(), 'password' => $request->string('password')->toString()]);

        return $this->done("Akun {$user->name} dibuat. Kata sandi awal harus diganti saat pertama masuk.");
    }

    public function update(UserRequest $request, User $user, UpdateUser $update): RedirectResponse
    {
        $update->handle($request->user(), $user, $request->account());

        return $this->done("Akun {$user->name} disimpan.");
    }

    public function deactivate(Request $request, User $user, DeactivateUser $deactivate): RedirectResponse
    {
        $deactivate->handle($request->user(), $user);

        return $this->done("Akun {$user->name} dinonaktifkan.");
    }

    public function reactivate(Request $request, User $user, ReactivateUser $reactivate): RedirectResponse
    {
        $reactivate->handle($request->user(), $user);

        return $this->done("Akun {$user->name} aktif lagi.");
    }

    public function password(Request $request, User $user, SetTemporaryPassword $reset): RedirectResponse
    {
        $data = $request->validate(['password' => $this->passwordRules()], attributes: ['password' => 'kata sandi']);

        $reset->handle($request->user(), $user, $data['password']);

        return $this->done("Kata sandi {$user->name} direset. Ia harus menggantinya saat masuk.");
    }

    public function pin(Request $request, User $user, ResetOwnerPin $reset): RedirectResponse
    {
        $reset->handle($request->user(), $user);

        return $this->done("PIN {$user->name} dihapus. Ia perlu mengatur PIN baru di Pengaturan.");
    }

    public function signInFactors(Request $request, User $user, ResetSignInFactors $reset): RedirectResponse
    {
        // The owner's own password: with a hijacked owner session, this is the step that would
        // complete taking over another account (DESIGN.md §14.2).
        $request->validate(['password' => ['required', 'string', 'current_password']], attributes: ['password' => 'kata sandi']);

        $reset->handle($request->user(), $user);

        return $this->done("2FA dan passkey {$user->name} dihapus. Ia bisa masuk dengan kata sandinya saja.");
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
