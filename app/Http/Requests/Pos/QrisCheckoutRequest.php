<?php

namespace App\Http\Requests\Pos;

/**
 * QRIS checkout, optionally mixed: with cash_amount the customer pays that much in cash and
 * the rest by QRIS (DESIGN.md §11). The bounds against the total are checked by the action.
 */
class QrisCheckoutRequest extends CartRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cash_amount' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ];
    }

    public function cashAmount(): ?int
    {
        $cash = $this->validated('cash_amount');

        return $cash === null ? null : (int) $cash;
    }
}
