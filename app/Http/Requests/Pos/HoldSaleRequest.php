<?php

namespace App\Http\Requests\Pos;

class HoldSaleRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'label' => ['nullable', 'string', 'max:60'],
        ];
    }
}
