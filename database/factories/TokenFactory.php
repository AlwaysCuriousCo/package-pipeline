<?php

namespace Database\Factories;

use App\Enums\TokenAbility;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Token>
 */
class TokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = 'pp_'.Str::random(40);

        return [
            'tokenable_type' => User::class,
            'tokenable_id' => User::factory(),
            'name' => fake()->words(2, true),
            'token_prefix' => substr($plain, 0, 8),
            'token' => hash('sha256', $plain),
            'abilities' => [TokenAbility::RepositoryRead->value],
        ];
    }
}
