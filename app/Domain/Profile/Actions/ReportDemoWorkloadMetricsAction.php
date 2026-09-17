<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use App\Domain\Profile\Support\InspectsQueueDepth;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ReportDemoWorkloadMetricsAction
{
    public function __construct(private readonly InspectsQueueDepth $queueDepth) {}

    /**
     * @param  array<int, string>  $usernames
     * @param  array<int, string>  $healthyUsernames
     * @return array{successful_refreshes: int, total_attempts: int, attempts_per_successful_refresh: float|null, oldest_waiting_job_age_seconds: int|null, average_healthy_seconds_to_sync: float|null, peak_memory_mb: float, profiles: Collection<int, Profile>}
     */
    public function execute(string $variant, array $usernames, array $healthyUsernames): array
    {
        $profileIds = Profile::whereIn('username', $usernames)->pluck('id');

        $attempts = ProfileRefreshAttempt::whereIn('profile_id', $profileIds)->get();
        $successful = $attempts->where('outcome', RefreshOutcome::Applied)->count();
        $total = $attempts->count();

        return [
            'successful_refreshes' => $successful,
            'total_attempts' => $total,
            'attempts_per_successful_refresh' => $successful > 0 ? round($total / $successful, 2) : null,
            'oldest_waiting_job_age_seconds' => $this->queueDepth->oldestWaitingJobAgeInSeconds(),
            'average_healthy_seconds_to_sync' => $this->averageHealthySecondsToSync($variant, $healthyUsernames),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'profiles' => Profile::whereIn('username', $usernames)
                ->orderBy('username')
                ->get(['username', 'likes', 'revision', 'last_synced_at', 'last_attempted_at', 'last_failure_reason', 'next_refresh_due_at']),
        ];
    }

    /**
     * @param  array<int, string>  $healthyUsernames
     */
    private function averageHealthySecondsToSync(string $variant, array $healthyUsernames): ?float
    {
        $startedAt = Cache::store('redis')->get("demo:{$variant}:started_at");

        if ($startedAt === null) {
            return null;
        }

        $secondsToSync = Profile::whereIn('username', $healthyUsernames)
            ->whereNotNull('last_synced_at')
            ->get()
            ->map(fn (Profile $profile) => $profile->last_synced_at->getTimestamp() - $startedAt);

        if ($secondsToSync->isEmpty()) {
            return null;
        }

        return round($secondsToSync->average(), 2);
    }
}
