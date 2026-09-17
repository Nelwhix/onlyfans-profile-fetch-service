<?php

namespace App\Domain\Profile\Commands;

use App\Domain\Profile\Actions\ReportProfileMetricsAction;
use Illuminate\Console\Command;

class ReportProfileMetricsCommand extends Command
{
    protected $signature = 'profiles:metrics';

    protected $description = 'Report the health of the profile refresh pipeline: overdue, failing, and recently synced counts, plus queue depth';

    public function handle(ReportProfileMetricsAction $action): void
    {
        $report = $action->execute();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total profiles', $report['total_profiles']],
                ['Currently overdue', $report['currently_overdue']],
                ['Currently failing (has reason)', $report['currently_failing']],
                ['Synced successfully (last 24h)', $report['synced_last_24h']],
                ['Oldest waiting job (seconds)', $report['oldest_waiting_job_age_seconds'] ?? 'n/a (queue empty)'],
                ['Peak memory to compute this report (MB)', $report['peak_memory_mb']],
            ]
        );
    }
}
