<?php

/*
| A second cashier, in a separate PHP process with its own database connection.
| Used by tests/Concurrency: sells one unit and prints the outcome as JSON.
|
|   php tests/Support/concurrent_checkout.php <user_id> <product_unit_id>
*/

use App\Actions\Sales\CompleteSale;
use App\Exceptions\InsufficientStockException;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $userId, $unitId] = $argv;

try {
    $sale = $app->make(CompleteSale::class)->handle(
        User::query()->findOrFail((int) $userId),
        [['product_unit_id' => (int) $unitId, 'qty' => 1]],
        paidAmount: 1_000_000,
    );
    echo json_encode(['ok' => true, 'sale' => $sale->number]);
} catch (InsufficientStockException) {
    echo json_encode(['ok' => false, 'error' => 'insufficient_stock']);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e::class.': '.$e->getMessage()]);
    exit(1);
}
