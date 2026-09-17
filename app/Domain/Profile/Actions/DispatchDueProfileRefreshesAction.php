<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class DispatchDueProfileRefreshesAction
{
    public function execute(): void
    {
        $dispatched = 0;

        Profile::query()
            ->where(fn ($query) => $query->whereNull('next_refresh_due_at')->orWhere('next_refresh_due_at', '<=', now()))
            ->chunkById(500, function (Collection $profiles) use (&$dispatched) {
                $profiles->each(function (Profile $profile) use (&$dispatched) {
                    RefreshProfileJob::dispatch($profile);
                    $dispatched++;
                });
            });

        Log::info('profile.dispatch.scheduled', [
            'due_profiles_dispatched' => $dispatched,
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ]);
    }
}
