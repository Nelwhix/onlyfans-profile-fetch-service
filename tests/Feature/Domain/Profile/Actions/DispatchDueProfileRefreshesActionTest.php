<?php

use App\Domain\Profile\Actions\DispatchDueProfileRefreshesAction;
use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use Illuminate\Support\Facades\Queue;

it('dispatches a refresh job for a profile that has never been scheduled', function () {
    Queue::fake([RefreshProfileJob::class]);

    $profile = Profile::factory()->create(['next_refresh_due_at' => null]);

    app(DispatchDueProfileRefreshesAction::class)->execute();

    Queue::assertPushed(RefreshProfileJob::class, fn (RefreshProfileJob $job) => $job->profile->is($profile));
});

it('dispatches a refresh job for a profile whose cadence has passed', function () {
    Queue::fake([RefreshProfileJob::class]);

    $profile = Profile::factory()->create(['next_refresh_due_at' => now()->subMinute()]);

    app(DispatchDueProfileRefreshesAction::class)->execute();

    Queue::assertPushed(RefreshProfileJob::class, fn (RefreshProfileJob $job) => $job->profile->is($profile));
});

it('does not dispatch a refresh job for a profile that is not due yet', function () {
    Queue::fake([RefreshProfileJob::class]);

    Profile::factory()->create(['next_refresh_due_at' => now()->addHour()]);

    app(DispatchDueProfileRefreshesAction::class)->execute();

    Queue::assertNothingPushed();
});
