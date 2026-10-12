<?php

namespace App\Http\Requests;

use App\Services\IngredientChecklistService;
use App\Services\RecommendationSnapshotService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIngredientChecklistRequest extends FormRequest
{
    private ?array $ownedSnapshot = null;

    public function snapshot(): array
    {
        return $this->ownedSnapshot ??= app(RecommendationSnapshotService::class)->find($this->session(), $this->route('search')) ?? abort(404);
    }

    public function authorize(): bool
    {
        $this->snapshot();

        return true;
    }

    public function rules(): array
    {
        $catalog = app(IngredientChecklistService::class)->state($this->session(), $this->snapshot())['catalog'];

        return ['confirmed' => ['present', 'array', 'list', 'max:105'],
            'confirmed.*' => ['required', 'string', 'distinct:strict', Rule::in(array_keys($catalog))]];
    }

    public function messages(): array
    {
        return ['confirmed.present' => 'Konfirmasi daftar bahan yang tersedia.',
            'confirmed.array' => 'Daftar bahan tidak valid.', 'confirmed.list' => 'Format daftar bahan tidak valid.',
            'confirmed.max' => 'Terlalu banyak bahan yang dipilih.', 'confirmed.min' => 'Pilih minimal satu bahan yang tersedia.',
            'confirmed.*.required' => 'Bahan tidak valid.', 'confirmed.*.string' => 'Bahan tidak valid.',
            'confirmed.*.distinct' => 'Bahan tidak boleh duplikat.', 'confirmed.*.in' => 'Bahan tidak berasal dari pencarian ini.'];
    }
}
