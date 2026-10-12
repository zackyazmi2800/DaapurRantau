<?php

namespace App\Http\Requests;

class GenerateAvailableRecipeRequest extends UpdateIngredientChecklistRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['confirmed'][] = 'min:1';

        return $rules;
    }
}
