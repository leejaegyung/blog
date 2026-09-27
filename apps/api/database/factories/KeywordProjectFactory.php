<?php

namespace Database\Factories;

use App\Models\KeywordProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordProject>
 */
class KeywordProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'keyword' => fake()->unique()->words(3, true),
            'category' => fake()->randomElement(['맛집', '제품', 'IT', '여행']),
        ];
    }
}
