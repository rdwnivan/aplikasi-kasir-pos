<?php

namespace App\Actions\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Sale;
use App\Services\Inventory\StockLedger;
use App\Services\Payments\MidtransClient;
use App\Services\Payments\MidtransException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QRIS state transitions, shared by the Midtrans webhook, the till's status polling and the
 * cashier's cancel button. Every transition locks the sale row and checks its current state,
 * so the same notification arriving twice (or webhook and polling racing) changes nothing the
 * second time.
 *
 *   pending_payment ──settlement──▶ completed   (stock issued here, exactly once)
 *   pending_payment ──expire/cancel/deny/failure──▶ cancelled
 *   cancelled ──settlement──▶ completed          (paid at the last second: the money is real)
 */
final class QrisPayments
{
    use BooksSaleStock;

    public const SETTLED = 'settled';

    public const CANCELLED = 'cancelled';

    public const UNCHANGED = 'unchanged';

    public const STOCK_SHORTAGE = 'stock_shortage';

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly MidtransClient $midtrans,
    ) {}

    /**
     * Apply a Midtrans transaction status to the sale.
     *
     * @return self::SETTLED|self::CANCELLED|self::UNCHANGED|self::STOCK_SHORTAGE
     */
    public function apply(Sale $sale, string $transactionStatus, ?string $fraudStatus = null): string
    {
        return match (MidtransClient::outcome($transactionStatus, $fraudStatus)) {
            MidtransClient::SETTLE => $this->settle($sale),
            MidtransClient::CANCEL => $this->cancel($sale),
            default => self::UNCHANGED,
        };
    }

    /** @return self::SETTLED|self::UNCHANGED|self::STOCK_SHORTAGE */
    public function settle(Sale $sale): string
    {
        try {
            return DB::transaction(function () use ($sale) {
                /** @var Sale $locked */
                $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

                if ($locked->payment_method?->usesQris() !== true
                    || ! in_array($locked->status, [SaleStatus::PendingPayment, SaleStatus::Cancelled], true)) {
                    return self::UNCHANGED;
                }

                $this->bookSaleStock($locked, $locked->user);

                $locked->forceFill([
                    'status' => SaleStatus::Completed,
                    'paid_amount' => $locked->total,
                    'change_amount' => 0,
                    'completed_at' => now(),
                ])->save();

                return self::SETTLED;
            });
        } catch (InsufficientStockException $e) {
            // The customer has paid but the goods sold out meanwhile. Leave the sale as it is
            // for the owner to resolve (refund or recount); retrying will not fix it.
            Log::error('QRIS settled but stock is short', ['sale' => $sale->number, 'error' => $e->getMessage()]);

            return self::STOCK_SHORTAGE;
        }
    }

    /** @return self::CANCELLED|self::UNCHANGED */
    public function cancel(Sale $sale): string
    {
        return DB::transaction(function () use ($sale) {
            /** @var Sale $locked */
            $locked = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SaleStatus::PendingPayment) {
                return self::UNCHANGED;
            }

            $locked->forceFill(['status' => SaleStatus::Cancelled])->save();

            return self::CANCELLED;
        });
    }

    /**
     * Ask Midtrans directly (server to server, so no signature needed) and apply the answer.
     * This is what makes QRIS work on a laptop where Midtrans cannot reach the webhook.
     */
    public function refresh(Sale $sale): string
    {
        try {
            $status = $this->midtrans->status((string) $sale->number);
        } catch (MidtransException $e) {
            Log::info('Midtrans status check failed', ['sale' => $sale->number, 'error' => $e->getMessage()]);

            return self::UNCHANGED;
        } finally {
            $sale->forceFill(['payment_checked_at' => now()])->saveQuietly();
        }

        return $this->apply(
            $sale,
            (string) ($status['transaction_status'] ?? ''),
            isset($status['fraud_status']) ? (string) $status['fraud_status'] : null,
        );
    }

    /**
     * The cashier gives up on a QR. If the customer did pay in the meantime, keep the sale.
     */
    public function cancelByCashier(Sale $sale): string
    {
        $result = $this->refresh($sale);
        if ($result !== self::UNCHANGED) {
            return $result;
        }

        try {
            $this->midtrans->cancel((string) $sale->number);
        } catch (MidtransException $e) {
            // Already expired or never reached Midtrans: either way it cannot be paid now.
            Log::info('Midtrans cancel refused', ['sale' => $sale->number, 'error' => $e->getMessage()]);
        }

        return $this->cancel($sale);
    }
}
