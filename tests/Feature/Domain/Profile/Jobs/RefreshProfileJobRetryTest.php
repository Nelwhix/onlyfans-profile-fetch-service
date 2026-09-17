<?php

use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Support\CouldNotFetchProfile;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * @param  array<string, mixed>|null  $body
 */
function fakeUpstreamStatus(int $status, ?array $body = null): void
{
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response($body ?? [], $status),
    ]);
}

it('does not retry a successful response', function () {
    $profile = Profile::factory()->create(['username' => 'madison420ivy']);
    fakeUpstreamStatus(Response::HTTP_OK, ['likes' => 120_000, 'revision' => 1]);

    $job = app()->make(RefreshProfileJob::class, ['profile' => $profile])->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased()->assertNotFailed();
});

it('retries a rate-limited response with a randomized delay', function () {
    $profile = Profile::factory()->create(['username' => 'madison420ivy']);
    fakeUpstreamStatus(Response::HTTP_TOO_MANY_REQUESTS);

    $job = app()->make(RefreshProfileJob::class, ['profile' => $profile])->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased();
    expect($job->job->releaseDelay)
        ->toBeGreaterThanOrEqual(RefreshProfileJob::RATE_LIMIT_MIN_DELAY_SECONDS)
        ->toBeLessThanOrEqual(RefreshProfileJob::RATE_LIMIT_MAX_DELAY_SECONDS);
});

it('retries a temporary server error with a backed-off delay', function () {
    $profile = Profile::factory()->create(['username' => 'madison420ivy']);
    fakeUpstreamStatus(Response::HTTP_INTERNAL_SERVER_ERROR);

    $job = app()->make(RefreshProfileJob::class, ['profile' => $profile])->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: RefreshProfileJob::BACKOFF_BASE_SECONDS);
});

it('retries a connection failure with a backed-off delay', function () {
    $profile = Profile::factory()->create(['username' => 'madison420ivy']);
    Http::preventStrayRequests();
    Http::fake([config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::failedConnection()]);

    $job = app()->make(RefreshProfileJob::class, ['profile' => $profile])->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased(delay: RefreshProfileJob::BACKOFF_BASE_SECONDS);
});

it('fails immediately without retrying on a permanent failure status', function () {
    $profile = Profile::factory()->create(['username' => 'madison420ivy']);
    fakeUpstreamStatus(Response::HTTP_NOT_FOUND);

    $job = app()->make(RefreshProfileJob::class, ['profile' => $profile])->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased()->assertFailedWith(CouldNotFetchProfile::class);
});

it('records the failure reason and reschedules the profile when the job is permanently failed', function () {
    $profile = Profile::factory()->create([
        'last_failure_reason' => null,
        'next_refresh_due_at' => null,
    ]);

    app()->make(RefreshProfileJob::class, ['profile' => $profile])
        ->failed(new RuntimeException('simulated failure writing the audit row'));

    $profile->refresh();

    expect($profile->last_failure_reason)->toBe('simulated failure writing the audit row')
        ->and($profile->last_attempted_at)->not->toBeNull()
        ->and($profile->next_refresh_due_at)->not->toBeNull();
});
