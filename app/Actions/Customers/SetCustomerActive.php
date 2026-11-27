<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use App\Models\User;

/**
 * Owner only. A deactivated customer cannot take new kasbon but can still pay off what they
 * owe; nothing is deleted.
 */
final class SetCustomerActive
{
    use SavesCustomers;

    public function handle(User $actor, Customer $customer, bool $active): Customer
    {
        $this->ensureOwner($actor);

        $customer->forceFill(['deactivated_at' => $active ? null : ($customer->deactivated_at ?? now())])->save();

        return $customer;
    }
}
