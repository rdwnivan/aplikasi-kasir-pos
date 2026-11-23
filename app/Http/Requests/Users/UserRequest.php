<?php

namespace App\Http\Requests\Users;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Add (with a starting password) or edit an account from Pengguna. */
class UserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $user = $this->route('user');
        $editing = $user instanceof User;

        return [
            ...$this->profileRules($editing ? $user->id : null),
            'role' => ['required', Rule::enum(Role::class)],
            ...($editing ? [] : ['password' => $this->passwordRules()]),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama', 'email' => 'email', 'role' => 'role', 'password' => 'kata sandi'];
    }

    /** @return array{name: string, email: string, role: Role} */
    public function account(): array
    {
        return [
            'name' => $this->string('name')->trim()->toString(),
            'email' => $this->string('email')->toString(),
            'role' => $this->enum('role', Role::class) ?? Role::Cashier,
        ];
    }
}
