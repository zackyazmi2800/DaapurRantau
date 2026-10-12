<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClearHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted']];
    }

    public function messages(): array
    {
        return ['confirm.required' => 'Konfirmasi penghapusan seluruh riwayat diperlukan.',
            'confirm.accepted' => 'Konfirmasi penghapusan seluruh riwayat diperlukan.'];
    }
}
