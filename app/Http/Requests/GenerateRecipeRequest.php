<?php

namespace App\Http\Requests;

use App\Services\RecipeMatchingService;
use App\Support\RecipeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateRecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalize = fn (string $value): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
        $ingredients = $this->input('ingredients');

        $this->merge([
            'ingredients' => is_array($ingredients)
                ? array_map(fn ($value) => is_string($value) ? app(RecipeMatchingService::class)->normalize($value) : $value, $ingredients)
                : $ingredients,
            'allergies' => is_string($this->input('allergies')) ? $normalize($this->input('allergies')) : $this->input('allergies'),
        ]);
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', Rule::in([RecipeMode::NORMAL, RecipeMode::STRICT])],
            'ingredients' => ['required', 'array', 'list', 'min:1', 'max:'.($this->input('mode') === RecipeMode::STRICT ? 105 : 15)],
            'ingredients.*' => ['bail', 'required', 'string', 'max:60', 'distinct:strict', "regex:/\A[\pL\pM\pN .’'()\-]+\z/u"],
            'servings' => ['nullable', 'integer', 'between:1,10'],
            'preference' => ['nullable', Rule::in(['bebas', 'hemat', 'cepat', 'pedas'])],
            'allergies' => ['nullable', 'string', 'max:600', "regex:/\A[\pL\pM\pN ,.’'()\-]+\z/u"],
        ];
    }

    public function messages(): array
    {
        return [
            'mode.in' => 'Mode rekomendasi tidak valid.',
            'ingredients.required' => 'Masukkan minimal satu bahan makanan.',
            'ingredients.array' => 'Daftar bahan harus berupa array.',
            'ingredients.list' => 'Format daftar bahan tidak valid.',
            'ingredients.min' => 'Masukkan minimal satu bahan makanan.',
            'ingredients.max' => 'Maksimal '.($this->input('mode') === RecipeMode::STRICT ? 105 : 15).' bahan makanan boleh dipilih.',
            'ingredients.*.required' => 'Nama bahan tidak boleh kosong.',
            'ingredients.*.string' => 'Setiap nama bahan harus berupa teks.',
            'ingredients.*.max' => 'Nama bahan maksimal 60 karakter.',
            'ingredients.*.distinct' => 'Bahan yang sama tidak boleh dimasukkan dua kali.',
            'ingredients.*.regex' => 'Nama bahan hanya boleh berisi huruf, angka, spasi, titik, tanda petik, kurung, atau tanda hubung.',
            'servings.integer' => 'Jumlah porsi harus berupa bilangan bulat.',
            'servings.between' => 'Jumlah porsi harus antara 1 dan 10.',
            'preference.in' => 'Pilih preferensi bebas, hemat, cepat, atau pedas.',
            'allergies.string' => 'Bahan yang dihindari harus berupa teks.',
            'allergies.max' => 'Bahan yang dihindari maksimal 600 karakter.',
            'allergies.regex' => 'Bahan yang dihindari mengandung karakter yang tidak diperbolehkan.',
        ];
    }
}
