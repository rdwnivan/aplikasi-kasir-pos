<?php

/*
| A second cashier, in a separate PHP process with its own database connection.
| Used by tests/Concurrency/QrisCustomerPaymentRaceTest: starts a QRIS pelunasan for one
| customer (Midtrans faked) and prints the outcome as JSON.
|
|   php tests/Support/concurrent_qris_payment.php <user_id> <customer_id> <amount>
*/

use App\Actions\Customers\StartQrisCustomerPayment;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['services.midtrans.server_key' => 'SB-Mid-server-RACE', 'services.midtrans.is_production' => false]);
Http::preventStrayRequests();
Http::fake(['*/v2/charge' => Http::response([
    'status_code' => '201',
    'transaction_id' => 'trx-race',
    'transaction_status' => 'pending',
    'actions' => [],
    'expiry_time' => now()->addMinutes(15)->format('Y-m-d H:i:s'),
], 201)]);

[, $userId, $customerId, $amount] = $argv;
$user = User::query()->findOrFail((int) $userId);
$customer = Customer::query()->findOrFail((int) $customerId);

try {
    $payment = $app->make(StartQrisCustomerPayment::class)->handle($user, $customer, (int) $amount);
    echo json_encode(['ok' => true, 'payment' => $payment->number]);
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => collect($e->errors())->flatten()->implode(' ')]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
