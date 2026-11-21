<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A payment provider callback exactly as received, with what was done about it.
 *
 * @property int $id
 * @property string $provider
 * @property string|null $order_id
 * @property string|null $transaction_status
 * @property bool $signature_valid
 * @property string $result
 * @property array<string, mixed> $payload
 */
class PaymentNotification extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
        ];
    }
}
