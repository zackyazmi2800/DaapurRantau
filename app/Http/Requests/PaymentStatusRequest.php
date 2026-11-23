<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return ['order_id' => ['required', 'uuid']];
    }
}
