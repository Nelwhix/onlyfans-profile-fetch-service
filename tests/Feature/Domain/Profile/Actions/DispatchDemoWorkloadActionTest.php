<?php

use App\Domain\Profile\Actions\DispatchDemoWorkloadAction;
use App\Domain\Profile\Jobs\NaiveRefreshProfileJob;
use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use Illuminate\Support\Facades\Queue;

it('collapses the noisy burst to a single dispatch for the real variant', function () {
    Queue::fake([RefreshProfileJob::class, NaiveRefreshProfileJob::class]);

    $pending = app(DispatchDemoWorkloadAction::class)->execute('real');

    Queue::assertPushed(RefreshProfileJob::class, count(DispatchDemoWorkloadAction::HEALTHY_USERNAMES) + 1);
    Queue::assertPushed(RefreshProfileJob::class, fn (RefreshProfileJob $job) => $job->profile->username === DispatchDemoWorkloadAction::NOISY_USERNAME);
    Queue::assertNotPushed(NaiveRefreshProfileJob::class);

    // 8 jobs dispatched, collapsed by the ShouldBeUnique lock to only 4 jobs in the queue, 1 for the noisy job, 3 for the healthy
    expect($pending)->toBe(count(DispatchDemoWorkloadAction::HEALTHY_USERNAMES) + 1);
});

it('uses NaiveRefreshProfileJob for the naive variant, piling up duplicate dispatches for the noisy account', function () {
    Queue::fake([RefreshProfileJob::class, NaiveRefreshProfileJob::class]);

    $pending = app(DispatchDemoWorkloadAction::class)->execute('naive');

    Queue::assertPushed(NaiveRefreshProfileJob::class, count(DispatchDemoWorkloadAction::HEALTHY_USERNAMES) + 5);
    Queue::assertNotPushed(RefreshProfileJob::class);

    // 8 jobs dispatched, all 8 in the queue to be processed.
    expect($pending)->toBe(count(DispatchDemoWorkloadAction::HEALTHY_USERNAMES) + 5);
});

it('starts every demo run from a clean slate, discarding any prior attempt history', function () {
    Queue::fake([RefreshProfileJob::class]);

    $previousRun = Profile::factory()->create(['username' => DispatchDemoWorkloadAction::NOISY_USERNAME]);
    ProfileRefreshAttempt::factory()->create(['profile_id' => $previousRun->id]);

    app(DispatchDemoWorkloadAction::class)->execute('real');

    expect(Profile::whereKey($previousRun->id)->exists())->toBeFalse()
        ->and(ProfileRefreshAttempt::where('profile_id', $previousRun->id)->exists())->toBeFalse()
        ->and(Profile::where('username', DispatchDemoWorkloadAction::NOISY_USERNAME)->exists())->toBeTrue();
});
