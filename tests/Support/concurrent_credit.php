<?php

/*
| A second cashier, in a separate PHP process with its own database connection.
| Used by tests/Concurrency/CustomerCreditRaceTest: gives kasbon or takes a payment for one
| customer and prints the outcome as JSON.
|
|   php tests/Support/concurrent_credit.php sale <user_id> <customer_id> <product_unit_id>
|   php tests/Support/concurrent_credit.php pay <user_id> <customer_id> <amount>
*/

use App\Actions\Customers\ReceiveCustomerPayment;
use App\Actions\Sales\CompleteSale;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $userId, $customerId, $arg] = $argv;
$user = User::query()->findOrFail((int) $userId);
$customer = Customer::query()->findOrFail((int) $customerId);

try {
    if ($mode === 'sale') {
        $sale = $app->make(CompleteSale::class)->handle($user, [['product_unit_id' => (int) $arg, 'qty' => 1]], paidAmount: 0, customer: $customer);
        echo json_encode(['ok' => true, 'sale' => $sale->number]);
    } else {
        $payment = $app->make(ReceiveCustomerPayment::class)->handle($user, $customer, (int) $arg);
        echo json_encode(['ok' => true, 'payment' => $payment->number]);
    }
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => collect($e->errors())->flatten()->implode(' ')]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
