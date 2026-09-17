<?php

namespace Database\Factories;

use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Symfony\Component\HttpFoundation\Response;

/**
 * @extends Factory<ProfileRefreshAttempt>
 */
#[UseModel(ProfileRefreshAttempt::class)]
class ProfileRefreshAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'attempt_number' => 1,
            'outcome' => RefreshOutcome::Applied,
            'http_status' => Response::HTTP_OK,
            'failure_reason' => null,
        ];
    }
}
