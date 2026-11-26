<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Enums\AccountEventType;
use App\Http\Controllers\Controller;
use App\Services\Audit\AccountLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * "Ganti kata sandi": the one page open to someone whose password an owner set (a new
 * account or a reset), so the owner does not keep knowing it. See RequirePasswordChange.
 */
class ForcedPasswordController extends Controller
{
    use PasswordValidationRules;

    public function edit(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return to_route('dashboard');
        }

        return Inertia::render('auth/change-password', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(Request $request): RedirectResponse|HttpResponse
    {
        $data = $request->validate(['password' => $this->passwordRules()], attributes: ['password' => 'kata sandi']);
        $user = $request->user();

        if (Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Pilih kata sandi yang berbeda dari kata sandi sementara.']);
        }

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
            AccountLog::record($user, AccountEventType::PasswordChanged, $user, ['via' => 'forced']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Kata sandi baru disimpan.']);

        return to_route('dashboard');
    }
}
