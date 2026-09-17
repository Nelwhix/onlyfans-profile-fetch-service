<?php

namespace Database\Factories;

use App\Domain\Profile\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
#[UseModel(Profile::class)]
class ProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => $this->faker->unique()->userName(),
            'likes' => $this->faker->numberBetween(0, 10000),
            'revision' => $this->faker->numberBetween(0, 100),
        ];
    }
}
