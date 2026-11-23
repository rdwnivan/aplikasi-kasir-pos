<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\DemoAccounts;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * There is no "delete my account": accounts carry sales, shifts and stock history, so an
 * owner deactivates them instead (Pengguna).
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        // Checked before fill(), while the account still has its demo email.
        $protected = DemoAccounts::isProtected($request->user());

        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            if ($protected) {
                throw ValidationException::withMessages(['email' => DemoAccounts::MESSAGE]);
            }

            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
