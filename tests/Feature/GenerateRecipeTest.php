<?php

namespace Tests\Feature;

use App\Services\GeminiRecipeService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Tests\Support\GeminiFixture;
use Tests\TestCase;

class GenerateRecipeTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->mock(GeminiRecipeService::class, function ($mock) {
            $mock->shouldReceive('generateRecipes')->andReturn($this->recipeData());
        });
    }

    public function test_empty_ingredients_are_rejected(): void
    {
        foreach ([[], ['ingredients' => []], ['ingredients' => ['   ']]] as $data) {
            $this->postJson(route('recommendations.store'), $data)->assertUnprocessable();
        }
    }

    public function test_one_ingredient_is_normalized_and_shown_without_api_calls(): void
    {
        $this->post(route('recommendations.store'), ['ingredients' => ['  TELUR  ']])
            ->assertOk()->assertViewIs('recommendations.preview')
            ->assertViewHas('data', fn ($data) => $data['ingredients'] === ['telur'])
            ->assertSee('Ini Dia Ide Masakan Buat Kamu!');
        Http::assertNothingSent();
    }

    public function test_multiple_ingredients_and_options_are_validated(): void
    {
        $this->post(route('recommendations.store'), [
            'ingredients' => ['Nasi', ' bawang   merah ', 'Telur'],
            'servings' => '2', 'preference' => 'hemat', 'allergies' => '  KACANG,   Udang ',
            'untrusted' => 'must not reach the view',
        ])->assertOk()->assertViewHas('data', fn ($data) => $data['ingredients'] === ['nasi', 'bawang merah', 'telur']
            && $data['servings'] === '2' && $data['preference'] === 'hemat'
            && $data['allergies'] === 'kacang, udang' && ! array_key_exists('untrusted', $data));
    }

    public function test_duplicates_after_normalization_are_rejected(): void
    {
        $this->postJson(route('recommendations.store'), ['ingredients' => ['Telur', ' telur ']])
            ->assertUnprocessable()->assertJsonValidationErrors(['ingredients.0', 'ingredients.1']);
    }

    public function test_dangerous_characters_are_rejected_and_escaped_on_form_return(): void
    {
        $attack = '<script>alert(1)</script>';
        $this->followingRedirects()->from(route('home'))->post(route('recommendations.store'), ['ingredients' => [$attack]])
            ->assertOk()->assertDontSee($attack, false)
            ->assertSee(e($attack), false)->assertSee('Periksa kembali inputmu:');
    }

    public function test_fifteen_ingredients_are_allowed_but_sixteen_are_rejected(): void
    {
        $ingredients = array_map(fn ($i) => 'bahan '.$i, range(1, 15));
        $this->postJson(route('recommendations.store'), ['ingredients' => $ingredients])->assertOk();
        $ingredients[] = 'bahan 16';
        $this->postJson(route('recommendations.store'), ['ingredients' => $ingredients])
            ->assertUnprocessable()->assertJsonValidationErrors('ingredients');
    }

    public function test_only_submitted_ingredients_are_used_after_removal(): void
    {
        $this->post(route('recommendations.store'), ['ingredients' => ['nasi']])
            ->assertOk()->assertViewHas('data', fn ($data) => $data['ingredients'] === ['nasi']);
    }

    public function test_invalid_shapes_lengths_and_options_are_rejected(): void
    {
        foreach ([
            ['ingredients' => 'telur'],
            ['ingredients' => [['telur']]],
            ['ingredients' => [123]],
            ['ingredients' => ['x' => 'telur']],
            ['ingredients' => [str_repeat('a', 61)]],
            ['ingredients' => ['telur'], 'servings' => 0],
            ['ingredients' => ['telur'], 'servings' => 11],
            ['ingredients' => ['telur'], 'servings' => 1.5],
            ['ingredients' => ['telur'], 'preference' => 'invalid'],
            ['ingredients' => ['telur'], 'allergies' => ['kacang']],
            ['ingredients' => ['telur'], 'allergies' => '<img src=x>'],
            ['ingredients' => ['telur'], 'allergies' => str_repeat('a', 601)],
        ] as $data) {
            $this->postJson(route('recommendations.store'), $data)->assertUnprocessable();
        }
    }

    public function test_optional_fields_may_be_empty_and_valid_options_are_accepted(): void
    {
        foreach (['bebas', 'hemat', 'cepat', 'pedas'] as $preference) {
            $this->postJson(route('recommendations.store'), [
                'ingredients' => ['telur'], 'servings' => null, 'allergies' => null, 'preference' => $preference,
            ])->assertOk();
        }
    }

    public function test_validation_redirect_preserves_ingredients_and_options(): void
    {
        $this->followingRedirects()->from(route('home'))->post(route('recommendations.store'), [
            'ingredients' => ['telur'], 'servings' => 11, 'preference' => 'pedas', 'allergies' => 'udang',
        ])->assertOk()->assertSee('Jumlah porsi harus antara 1 dan 10.')
            ->assertSee('value="telur"', false)->assertSee('udang')->assertSee('value="11"', false);
    }
}
