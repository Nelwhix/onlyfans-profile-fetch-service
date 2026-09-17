<?php

use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use Illuminate\Support\Facades\Http;

it('does not corrupt data or double-count a sync when a completed job is replayed', function () {
    $profile = Profile::factory()->create([
        'username' => 'madison420ivy',
        'likes' => 0,
        'revision' => null,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response(['likes' => 120_000, 'revision' => 10]),
    ]);

    // First worker: runs to completion, including the database write.
    app()->call([app()->make(RefreshProfileJob::class, ['profile' => $profile]), 'handle']);

    // The queue hands the identical job to a second worker, believing the
    // first died before acknowledging it - same profile, same response.
    app()->call([app()->make(RefreshProfileJob::class, ['profile' => $profile->fresh()]), 'handle']);

    $profile->refresh();
    $attempts = ProfileRefreshAttempt::where('profile_id', $profile->id)->orderBy('id')->get();

    expect($profile->likes)->toBe(120_000)
        ->and($profile->revision)->toBe(10)
        ->and($attempts)->toHaveCount(2)
        ->and($attempts[0]->outcome)->toBe(RefreshOutcome::Applied)
        ->and($attempts[1]->outcome)->toBe(RefreshOutcome::Stale);
});
