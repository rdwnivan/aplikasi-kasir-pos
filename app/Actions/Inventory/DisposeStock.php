<?php

namespace App\Actions\Inventory;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\StockBatch;
use App\Models\StockDisposal;
use App\Models\User;
use App\Services\Documents\DocumentNumber;
use App\Services\Inventory\StockLedger;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Throws goods away from their batches (DESIGN.md §19 P6c): one `disposal` movement per line,
 * at the average HPP, under a numbered DSP document with a reason. Kept apart from stock
 * opname so the loss from expiry can be told from goods that went missing. Any batch may be
 * named, expired or not (spoilt goods); the ledger refuses more than the batch holds.
 */
final class DisposeStock
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * @param  list<array{stock_batch_id: int, qty: int}>  $lines  qty in base units
     */
    public function handle(User $user, array $lines, string $reason): StockDisposal
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi.']);
        }

        $lines = array_values(array_filter($lines, fn (array $l) => $l['qty'] !== 0));
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Pilih minimal satu batch yang dibuang.']);
        }

        $batches = StockBatch::query()->with('product')->whereIn('id', array_column($lines, 'stock_batch_id'))->get()->keyBy('id');
        $errors = [];
        foreach ($lines as $i => $line) {
            if ($line['qty'] < 0) {
                $errors["items.$i.qty"] = 'Jumlah tidak boleh negatif.';
            } elseif (! $batches->has($line['stock_batch_id'])) {
                $errors["items.$i.stock_batch_id"] = 'Batch tidak ditemukan.';
            }
        }
        if (count(array_unique(array_column($lines, 'stock_batch_id'))) !== count($lines)) {
            $errors['items'] = 'Satu batch hanya boleh muncul sekali.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Same lock order as every other stock document: by product id.
        $order = array_keys($lines);
        usort($order, fn (int $a, int $b) => [$batches[$lines[$a]['stock_batch_id']]->product_id, $a] <=> [$batches[$lines[$b]['stock_batch_id']]->product_id, $b]);

        return DB::transaction(function () use ($user, $lines, $reason, $batches, $order) {
            $disposal = StockDisposal::create([
                'number' => $this->numbers->next(DocumentNumber::STOCK_DISPOSAL),
                'user_id' => $user->id,
                'reason' => $reason,
                'total_value' => 0,
            ]);

            $total = 0;
            foreach ($order as $i) {
                $batch = $batches[$lines[$i]['stock_batch_id']];
                $qty = $lines[$i]['qty'];

                try {
                    $movement = $this->ledger->issue($batch->product, $qty, StockMovementType::Disposal, $disposal, $user, $reason, batch: $batch);
                } catch (InsufficientStockException $e) {
                    throw ValidationException::withMessages(["items.$i.qty" => "Batch ini tinggal {$e->available}."]);
                }

                $value = (int) Decimal::round(Decimal::mul((string) $movement->unit_cost, (string) $qty), 0);
                $disposal->items()->create([
                    'stock_batch_id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'qty' => $qty,
                    'unit_cost' => $movement->unit_cost,
                    'value' => $value,
                ]);
                $total += $value;
            }

            $disposal->forceFill(['total_value' => $total])->save();

            return $disposal->load('items');
        });
    }
}
