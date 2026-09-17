<?php

use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use Illuminate\Support\Facades\Http;

it('fetches the profile from upstream and applies the response', function () {
    $profile = Profile::factory()->create([
        'username' => 'madison420ivy',
        'likes' => 0,
        'revision' => null,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response(['likes' => 120_000, 'revision' => 10]),
    ]);

    app()->call([app()->make(RefreshProfileJob::class, ['profile' => $profile]), 'handle']);
    $profile->refresh();

    expect($profile->likes)->toBe(120_000)
        ->and($profile->revision)->toBe(10);
});

it('is unique per profile', function () {
    $profile = Profile::factory()->create();

    expect(app()->make(RefreshProfileJob::class, ['profile' => $profile])->uniqueId())->toBe($profile->id);
});
