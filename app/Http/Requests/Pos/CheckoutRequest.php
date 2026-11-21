<?php

namespace App\Http\Requests\Pos;

class CheckoutRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'paid_amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
