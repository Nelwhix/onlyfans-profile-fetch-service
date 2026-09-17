<?php

namespace App\Domain\Profile\Commands;

use App\Domain\Profile\Actions\DispatchDueProfileRefreshesAction;
use Illuminate\Console\Command;

class DispatchDueProfileRefreshesCommand extends Command
{
    protected $signature = 'profiles:dispatch-due-refreshes';

    protected $description = 'Dispatch a refresh job for every profile whose next_refresh_due_at has passed (or was never set)';

    public function handle(DispatchDueProfileRefreshesAction $action): void
    {
        $this->info('Dispatching due profile refreshes...');

        $action->execute();
    }
}
