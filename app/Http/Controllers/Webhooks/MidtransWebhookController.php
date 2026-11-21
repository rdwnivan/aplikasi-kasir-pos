<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Sales\QrisPayments;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\PaymentNotification;
use App\Models\Sale;
use App\Services\Payments\MidtransClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Midtrans HTTP notification (Settings → Configuration → Payment Notification URL).
 *
 * Trust nothing in the body until the signature checks out; then match the order to a QRIS
 * sale with the same amount and apply the status. Every call is logged. Anything we decide
 * to ignore still gets a 200 so Midtrans stops retrying; only a bad signature gets a 403.
 */
class MidtransWebhookController extends Controller
{
    public function __invoke(Request $request, MidtransClient $midtrans, QrisPayments $payments): JsonResponse
    {
        $payload = $request->json()->all();
        $orderId = self::str($payload['order_id'] ?? null);
        $statusCode = self::str($payload['status_code'] ?? null);
        $grossAmount = self::str($payload['gross_amount'] ?? null);
        $transactionStatus = self::str($payload['transaction_status'] ?? null);

        $valid = $orderId !== '' && $midtrans->signatureMatches(
            $orderId,
            $statusCode,
            $grossAmount,
            self::str($payload['signature_key'] ?? null),
        );

        $log = fn (string $result) => PaymentNotification::create([
            'order_id' => $orderId !== '' ? mb_substr($orderId, 0, 64) : null,
            'transaction_id' => mb_substr(self::str($payload['transaction_id'] ?? null), 0, 64) ?: null,
            'transaction_status' => mb_substr($transactionStatus, 0, 30) ?: null,
            'status_code' => mb_substr($statusCode, 0, 10) ?: null,
            'gross_amount' => mb_substr($grossAmount, 0, 30) ?: null,
            'signature_valid' => $valid,
            'result' => $result,
            'payload' => $payload,
        ]);

        if (! $valid) {
            $log('invalid_signature');

            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $sale = Sale::query()->where('number', $orderId)->where('payment_method', PaymentMethod::Qris)->first();

        if ($sale === null) {
            $log('unknown_order');

            return response()->json(['message' => 'Unknown order, ignored.']);
        }

        if ((int) round((float) $grossAmount) !== $sale->total) {
            $log('amount_mismatch');

            return response()->json(['message' => 'Amount does not match, ignored.']);
        }

        $result = $payments->apply(
            $sale,
            $transactionStatus,
            isset($payload['fraud_status']) ? self::str($payload['fraud_status']) : null,
        );
        $log($result);

        return response()->json(['message' => 'OK', 'result' => $result]);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
