<?php

namespace App\Actions\Customers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\User;

/**
 * Adds a customer. Cashiers may do this from the till so a first kasbon need not wait for
 * the owner, but always with the store's default limit (pos.credit.default_limit); only an
 * owner picks a limit.
 */
final class CreateCustomer
{
    use SavesCustomers;

    /** @param array<string, mixed> $data name, phone, address, note, credit_limit (owner only) */
    public function handle(User $actor, array $data): Customer
    {
        $fields = $this->cleanFields($data);
        $limit = $actor->hasRole(Role::Owner->value) && array_key_exists('credit_limit', $data) && $data['credit_limit'] !== null
            ? self::limit($data['credit_limit'])
            : (int) config('pos.credit.default_limit');

        $customer = new Customer([...$fields, 'credit_limit' => $limit, 'created_by' => $actor->id]);
        $customer->save();

        return $customer->refresh(); // pick up the defaults the database filled in (balance)
    }
}
