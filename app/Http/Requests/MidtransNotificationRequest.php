<?php

namespace App\Http\Requests;

use App\Services\MidtransSandboxService;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MidtransNotificationRequest extends FormRequest
{
    // Runs before validation, so a disabled endpoint never reveals itself with a 422.
    public function authorize(MidtransSandboxService $gateway): bool
    {
        return $gateway->webhookEnabled();
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    public function rules(): array
    {
        return [
            // Application orders are UUIDs; other signed orders (Dashboard Test Notification) are acknowledged only after the signature check.
            'order_id' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.~-]+\z/'],
            'gross_amount' => ['required', 'string', 'max:20'],
            'status_code' => ['required', 'string', 'digits:3'],
            'signature_key' => ['required', 'string', 'size:128'],
            'transaction_status' => ['required', 'string', 'in:pending,authorize,capture,settlement,cancel,deny,expire,failure,refund,partial_refund,chargeback,partial_chargeback'],
            'fraud_status' => ['sometimes', 'string', 'in:accept,challenge,deny'],
        ];
    }
}
