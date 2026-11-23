<?php

/*
| A second warehouse clerk, in a separate PHP process with its own database connection.
| Used by tests/Concurrency: receives `qty` of one purchase order line and prints the outcome.
|
|   php tests/Support/concurrent_po_receive.php <user_id> <purchase_order_id> <item_id> <qty>
*/

use App\Actions\Purchasing\ReceivePurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $orderId, $itemId, $qty] = $argv;

try {
    $po = PurchaseOrder::query()->with('items')->findOrFail((int) $orderId);
    $receipt = $app->make(ReceivePurchaseOrder::class)->handle(
        $po,
        User::query()->findOrFail((int) $userId),
        [['purchase_order_item_id' => (int) $itemId, 'qty' => (int) $qty, 'unit_price' => 1000]],
    );
    echo json_encode(['ok' => true, 'receipt' => $receipt->number]);
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'error' => 'validation', 'messages' => $e->errors()]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
