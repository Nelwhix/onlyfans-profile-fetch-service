<?php

namespace App\Domain\Profile\Commands;

use App\Domain\Profile\Actions\DispatchDemoWorkloadAction;
use App\Domain\Profile\Actions\ReportDemoWorkloadMetricsAction;
use App\Domain\Profile\Models\Profile;
use Illuminate\Console\Command;

class ReportDemoWorkloadMetricsCommand extends Command
{
    protected $signature = 'profiles:demo-report {--variant=real : "real" or "naive" - must match what was passed to profiles:demo-workload}';

    protected $description = 'Report successful refreshes, attempts per successful refresh, and oldest waiting job age for the demo workload';

    public function handle(ReportDemoWorkloadMetricsAction $action): void
    {
        $variant = $this->option('variant');
        $usernames = [DispatchDemoWorkloadAction::NOISY_USERNAME, ...DispatchDemoWorkloadAction::HEALTHY_USERNAMES];

        $report = $action->execute($variant, $usernames, DispatchDemoWorkloadAction::HEALTHY_USERNAMES);

        $this->info("Variant: {$variant}");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Successful refreshes', $report['successful_refreshes']],
                ['Total attempts', $report['total_attempts']],
                ['Attempts per successful refresh', $report['attempts_per_successful_refresh'] ?? 'n/a (no successes yet)'],
                ['Oldest waiting job age (seconds)', $report['oldest_waiting_job_age_seconds'] ?? 'n/a (queue empty)'],
                ['Avg. seconds for a healthy account to sync', $report['average_healthy_seconds_to_sync'] ?? 'n/a (none synced yet)'],
                ['Peak memory to compute this report (MB)', $report['peak_memory_mb']],
            ]
        );

        $this->table(
            ['Username', 'Likes', 'Revision', 'Last synced', 'Last attempted', 'Failure reason', 'Next refresh due'],
            $report['profiles']->map(fn (Profile $profile) => [
                $profile->username,
                $profile->likes,
                $profile->revision,
                $profile->last_synced_at?->toDateTimeString() ?? '-',
                $profile->last_attempted_at?->toDateTimeString() ?? '-',
                $profile->last_failure_reason ?? '-',
                $profile->next_refresh_due_at?->toDateTimeString() ?? '-',
            ])
        );
    }
}
