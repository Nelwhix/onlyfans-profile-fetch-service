<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Support\InspectsQueueDepth;

class ReportProfileMetricsAction
{
    public function __construct(private readonly InspectsQueueDepth $queueDepth) {}

    /**
     * @return array{total_profiles: int, currently_overdue: int, currently_failing: int, synced_last_24h: int, oldest_waiting_job_age_seconds: int|null, peak_memory_mb: float}
     */
    public function execute(): array
    {
        return [
            'total_profiles' => Profile::count(),
            'currently_overdue' => Profile::where('next_refresh_due_at', '<=', now())->count(),
            'currently_failing' => Profile::whereNotNull('last_failure_reason')->count(),
            'synced_last_24h' => Profile::where('last_synced_at', '>=', now()->subDay())->count(),
            'oldest_waiting_job_age_seconds' => $this->queueDepth->oldestWaitingJobAgeInSeconds(),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }
}
