<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Support\DemoAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The owner's approval PIN (for returns), set after confirming the account password. */
class OwnerPinController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/pin', [
            'hasPin' => $request->user()->pin_hash !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed'],
        ], [
            'pin.regex' => 'PIN terdiri dari 4–6 angka.',
        ], [
            'pin' => 'PIN',
        ]);

        if (DemoAccounts::isProtected($request->user())) {
            throw ValidationException::withMessages(['pin' => DemoAccounts::MESSAGE]);
        }

        $request->user()->forceFill(['pin_hash' => Hash::make($data['pin'])])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'PIN owner disimpan.']);

        return back();
    }
}
