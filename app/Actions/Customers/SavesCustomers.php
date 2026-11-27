<?php

namespace App\Actions\Customers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Shared checks for adding and changing customers (DESIGN.md §17). */
trait SavesCustomers
{
    /**
     * Clean the editable fields: trimmed name, phone as digits only, empty strings to null.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, phone: string|null, address: string|null, note: string|null}
     */
    private function cleanFields(array $data, ?Customer $except = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama pelanggan wajib diisi.']);
        }

        $phone = Customer::normalizePhone(isset($data['phone']) ? (string) $data['phone'] : null);
        if ($phone !== null) {
            $taken = Customer::query()->where('phone', $phone)->when($except, fn ($q, Customer $c) => $q->whereKeyNot($c->id))->first();
            if ($taken !== null) {
                throw ValidationException::withMessages(['phone' => "Nomor HP ini sudah dipakai {$taken->name}."]);
            }
        }

        $text = fn (string $key) => ($v = trim((string) ($data[$key] ?? ''))) === '' ? null : $v;

        return ['name' => $name, 'phone' => $phone, 'address' => $text('address'), 'note' => $text('note')];
    }

    private function ensureOwner(User $actor): void
    {
        if (! $actor->hasRole(Role::Owner->value)) {
            throw ValidationException::withMessages(['customer' => 'Hanya owner yang bisa mengubah data pelanggan.']);
        }
    }

    private static function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 0) {
            throw ValidationException::withMessages(['credit_limit' => 'Batas kasbon tidak boleh negatif.']);
        }

        return $limit;
    }
}
