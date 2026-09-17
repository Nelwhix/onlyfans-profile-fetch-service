<?php

namespace App\Domain\Profile\Commands;

use App\Domain\Profile\Actions\DispatchDemoWorkloadAction;
use Illuminate\Console\Command;

class DispatchDemoWorkloadCommand extends Command
{
    protected $signature = 'profiles:demo-workload {--variant=real : "real" (RefreshProfileJob) or "naive" (NaiveRefreshProfileJob)}';

    protected $description = 'Dispatch a small repeatable workload: one rate-limited account, three healthy ones';

    public function handle(DispatchDemoWorkloadAction $action): void
    {
        $variant = $this->option('variant');

        $this->info("Dispatching demo workload (variant: {$variant})...");

        $pending = $action->execute($variant);

        $this->info("Jobs waiting right now, before any worker has run: {$pending}");
        $this->info("then run: make demo-report VARIANT={$variant}");
    }
}
