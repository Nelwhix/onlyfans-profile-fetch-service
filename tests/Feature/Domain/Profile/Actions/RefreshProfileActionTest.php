<?php

use App\Domain\Profile\Actions\RefreshProfileAction;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

it('does not overwrite likes with zero when the response nests likes under profile', function () {
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
