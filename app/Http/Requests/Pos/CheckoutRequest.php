<?php

namespace App\Http\Requests\Pos;

use App\Models\Customer;
use Illuminate\Validation\Rule;

/**
 * Cash checkout, or kasbon (payment_method=credit) for a customer, where paid_amount is the
 * cash down payment (DESIGN.md §17).
 */
class CheckoutRequest extends CartRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'payment_method' => ['nullable', Rule::in(['cash', 'credit'])],
            'customer_id' => ['nullable', 'required_if:payment_method,credit', 'integer'],
            'paid_amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }

    public function messages(): array
    {
        return ['customer_id.required_if' => 'Pilih pelanggan untuk kasbon.'];
    }

    /** The customer for kasbon, or null for a cash sale. */
    public function creditCustomer(): ?Customer
    {
        if ($this->validated('payment_method') !== 'credit') {
            return null;
        }

        return Customer::query()->findOrFail((int) $this->validated('customer_id'));
    }
}
