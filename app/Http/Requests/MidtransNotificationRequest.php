<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MidtransNotificationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'order_id' => ['required', 'uuid'],
            'gross_amount' => ['required', 'string', 'max:20'],
            'status_code' => ['required', 'string', 'digits:3'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string', 'in:pending,authorize,capture,settlement,cancel,deny,expire,failure'],
            'fraud_status' => ['sometimes', 'string', 'in:accept,challenge,deny'],
        ];
    }
}
