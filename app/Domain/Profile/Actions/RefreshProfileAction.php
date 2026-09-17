<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use App\Domain\Profile\Support\CouldNotParseProfileResponse;
use App\Domain\Profile\ValueObjects\ParsedProfileResponse;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RefreshProfileAction
{
    private const int FAILURE_RETRY_DELAY_MINUTES = 30;

    public function execute(Profile $profile, UpstreamResponse $response, int $attemptNumber = 1): void
    {
        $now = CarbonImmutable::now();

        try {
            $parsed = ParsedProfileResponse::fromUpstreamResponse($response);
        } catch (CouldNotParseProfileResponse $e) {
            DB::transaction(function () use ($profile, $now, $attemptNumber, $response, $e) {
                $profile->update([
                    'last_attempted_at' => $now,
                    'last_failure_reason' => $e->getMessage(),
                    'next_refresh_due_at' => $now->addMinutes(self::FAILURE_RETRY_DELAY_MINUTES),
                ]);

                ProfileRefreshAttempt::create([
                    'profile_id' => $profile->id,
                    'attempt_number' => $attemptNumber,
                    'outcome' => RefreshOutcome::Failed,
                    'http_status' => $response->status,
                    'failure_reason' => $e->getMessage(),
                ]);
            });

            return;
        }

        DB::transaction(function () use ($profile, $parsed, $now, $attemptNumber, $response) {
            $applied = Profile::query()
                ->whereKey($profile->id)
                ->where(fn ($query) => $query->whereNull('revision')->orWhere('revision', '<', $parsed->revision))
                ->update([
                    'likes' => $parsed->likes,
                    'revision' => $parsed->revision,
                    'last_attempted_at' => $now,
                    'last_synced_at' => $now,
                    'last_failure_reason' => null,
                    'next_refresh_due_at' => $this->nextRefreshDueAt($parsed->likes, $now),
                ]);

            ProfileRefreshAttempt::create([
                'profile_id' => $profile->id,
                'attempt_number' => $attemptNumber,
                'outcome' => $applied ? RefreshOutcome::Applied : RefreshOutcome::Stale,
                'http_status' => $response->status,
                'failure_reason' => null,
            ]);

            if (! $applied) {
                $profile->update(['last_attempted_at' => $now]);
            }
        });
    }

    private function nextRefreshDueAt(int $likes, CarbonImmutable $from): CarbonImmutable
    {
        return $likes > 100_000
            ? $from->addHours(24)
            : $from->addHours(72);
    }
}
