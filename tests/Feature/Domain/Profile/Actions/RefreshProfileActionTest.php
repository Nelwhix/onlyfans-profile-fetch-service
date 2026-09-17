<?php

use App\Domain\Profile\Actions\RefreshProfileAction;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

it('reads likes from the nested profile object when the response uses the new format', function () {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $currentTime = now();
    $profile = Profile::factory()->create([
        'likes' => 120_000,
        'revision' => 10,
        'last_synced_at' => $currentTime,
        'last_attempted_at' => $currentTime,
    ]);

    $this->travelTo(Carbon::parse('2026-01-02 00:00:00'));

    $response = new UpstreamResponse(Response::HTTP_OK, [
        'profile' => ['likes' => 121_000],
        'revision' => 11,
    ]);

    app(RefreshProfileAction::class)->execute($profile, $response);
    $profile->refresh();

    expect($profile->likes)->toBe(121_000)
        ->and($profile->revision)->toBe(11);
});

it('does not erase valid data or mark a failed response as a successful refresh', function () {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $lastSyncedAt = now();
    $profile = Profile::factory()->create([
        'likes' => 120_000,
        'revision' => 10,
        'last_synced_at' => $lastSyncedAt,
        'last_attempted_at' => $lastSyncedAt,
    ]);

    $this->travelTo(Carbon::parse('2026-01-02 00:00:00'));

    $response = UpstreamResponse::failed(Response::HTTP_INTERNAL_SERVER_ERROR);

    app(RefreshProfileAction::class)->execute($profile, $response);
    $profile->refresh();

    expect($profile->likes)->toBe(120_000)
        ->and($profile->revision)->toBe(10)
        ->and($profile->last_synced_at->toDateTimeString())->toBe($lastSyncedAt->toDateTimeString())
        ->and($profile->last_attempted_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('reschedules a failing profile much sooner than its normal cadence', function () {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $profile = Profile::factory()->create([
        'likes' => 120_000,
        'revision' => 10,
        'next_refresh_due_at' => now()->addHours(24),
    ]);

    $response = UpstreamResponse::failed(Response::HTTP_INTERNAL_SERVER_ERROR);

    app(RefreshProfileAction::class)->execute($profile, $response);
    $profile->refresh();

    expect($profile->next_refresh_due_at->toDateTimeString())
        ->toBe(now()->addMinutes(30)->toDateTimeString());
});

it('does not change anything when the same response is applied twice', function () {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $profile = Profile::factory()->create([
        'likes' => 0,
        'revision' => null,
        'last_synced_at' => null,
        'last_attempted_at' => null,
    ]);

    $this->travelTo(Carbon::parse('2026-01-02 00:00:00'));

    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => 120_000, 'revision' => 10]);

    app(RefreshProfileAction::class)->execute($profile, $response);
    $firstSyncedAt = $profile->refresh()->last_synced_at;

    $this->travelTo(Carbon::parse('2026-01-03 00:00:00'));

    app(RefreshProfileAction::class)->execute($profile, $response);
    $profile->refresh();

    expect($profile->likes)->toBe(120_000)
        ->and($profile->revision)->toBe(10)
        ->and($profile->last_synced_at->toDateTimeString())->toBe($firstSyncedAt->toDateTimeString())
        ->and($profile->last_attempted_at->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('sets the next refresh cadence based on the resulting likes count', function (int $likes, int $expectedHours) {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $profile = Profile::factory()->create([
        'likes' => 0,
        'revision' => null,
    ]);

    $response = new UpstreamResponse(Response::HTTP_OK, ['likes' => $likes, 'revision' => 1]);

    app(RefreshProfileAction::class)->execute($profile, $response);
    $profile->refresh();

    expect($profile->next_refresh_due_at->toDateTimeString())
        ->toBe(now()->addHours($expectedHours)->toDateTimeString());
})->with([
    'above 100,000 likes refreshes every 24 hours' => [100_001, 24],
    'exactly 100,000 likes refreshes every 72 hours' => [100_000, 72],
    'below 100,000 likes refreshes every 72 hours' => [50_000, 72],
]);

it('keeps the newer accepted data when an older revision arrives after', function () {
    $this->travelTo(Carbon::parse('2026-01-01 00:00:00'));

    $profile = Profile::factory()->create([
        'likes' => 0,
        'revision' => null,
        'last_synced_at' => null,
        'last_attempted_at' => null,
    ]);

    $this->travelTo(Carbon::parse('2026-01-02 00:00:00'));

    $newerResponse = new UpstreamResponse(Response::HTTP_OK, [
        'profile' => ['likes' => 121_000],
        'revision' => 11,
    ]);
    app(RefreshProfileAction::class)->execute($profile, $newerResponse);

    $this->travelTo(Carbon::parse('2026-01-03 00:00:00'));

    $olderResponse = new UpstreamResponse(Response::HTTP_OK, ['likes' => 120_000, 'revision' => 10]);
    app(RefreshProfileAction::class)->execute($profile, $olderResponse);
    $profile->refresh();

    expect($profile->likes)->toBe(121_000)
        ->and($profile->revision)->toBe(11);
});
