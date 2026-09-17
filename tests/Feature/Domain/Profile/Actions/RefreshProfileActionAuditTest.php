<?php

use App\Domain\Profile\Actions\RefreshProfileAction;
use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Symfony\Component\HttpFoundation\Response;

afterEach(fn () => ProfileRefreshAttempt::flushEventListeners());

it('records an applied attempt', function () {
    $profile = Profile::factory()->create(['likes' => 0, 'revision' => null]);
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 120_000, 'revision' => 10]);

    app(RefreshProfileAction::class)->execute($profile, $response, attemptNumber: 2);

    $attempt = ProfileRefreshAttempt::where('profile_id', $profile->id)->sole();

    expect($attempt->attempt_number)->toBe(2)
        ->and($attempt->outcome)->toBe(RefreshOutcome::Applied)
        ->and($attempt->http_status)->toBe(Response::HTTP_OK)
        ->and($attempt->failure_reason)->toBeNull();
});

it('records a stale attempt', function () {
    $profile = Profile::factory()->create(['likes' => 120_000, 'revision' => 10]);
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 100_000, 'revision' => 5]);

    app(RefreshProfileAction::class)->execute($profile, $response);

    $attempt = ProfileRefreshAttempt::where('profile_id', $profile->id)->sole();

    expect($attempt->outcome)->toBe(RefreshOutcome::Stale);
});

it('records a failed attempt with the failure reason', function () {
    $profile = Profile::factory()->create(['likes' => 120_000, 'revision' => 10]);
    $response = UpstreamResponse::failed(Response::HTTP_INTERNAL_SERVER_ERROR);

    app(RefreshProfileAction::class)->execute($profile, $response, attemptNumber: 3);

    $attempt = ProfileRefreshAttempt::where('profile_id', $profile->id)->sole();

    expect($attempt->attempt_number)->toBe(3)
        ->and($attempt->outcome)->toBe(RefreshOutcome::Failed)
        ->and($attempt->http_status)->toBe(Response::HTTP_INTERNAL_SERVER_ERROR)
        ->and($attempt->failure_reason)->not->toBeNull();
});

it('leaves no trace of a refresh whose audit write failed', function () {
    $profile = Profile::factory()->create(['likes' => 0, 'revision' => null]);
    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 120_000, 'revision' => 10]);

    ProfileRefreshAttempt::creating(fn () => throw new RuntimeException('simulated failure writing the audit row'));

    rescue(fn () => app(RefreshProfileAction::class)->execute($profile, $response), report: false);

    expect($profile->fresh()->likes)->toBe(0)
        ->and($profile->fresh()->revision)->toBeNull()
        ->and(ProfileRefreshAttempt::where('profile_id', $profile->id)->exists())->toBeFalse();
});
