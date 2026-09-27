<?php

namespace Database\Factories;

use App\Models\KeywordProject;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            // 속성은 선언 순서대로 풀리므로 프로젝트를 먼저 만든 뒤 그 소유자를 따른다.
            'keyword_project_id' => KeywordProject::factory(),
            'user_id' => fn (array $attributes) => KeywordProject::find($attributes['keyword_project_id'])->user_id,
            'title' => fake()->sentence(),
        ];
    }
}
