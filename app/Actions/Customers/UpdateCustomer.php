<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;

/** Owner only: name, phone, address, note and credit limit. The balance never changes here. */
final class UpdateCustomer
{
    use SavesCustomers;

    /** @param array<string, mixed> $data */
    public function handle(User $actor, Customer $customer, array $data): Customer
    {
        $this->ensureOwner($actor);
        $fields = $this->cleanFields($data, except: $customer);

        $customer->fill([...$fields, 'credit_limit' => self::limit($data['credit_limit'] ?? $customer->credit_limit)])->save();

        return $customer;
    }
}
