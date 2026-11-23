<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function messages(): array
    {
        return ['plan.*' => 'Pilih paket Premium yang tersedia.'];
    }

    public function rules(): array
    {
        return ['plan' => ['required', 'string', 'in:weekly,monthly', 'exists:subscription_plans,code']];
    }
}
