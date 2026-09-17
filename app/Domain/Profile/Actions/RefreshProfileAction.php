<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\ValueObjects\UpstreamResponse;

class RefreshProfileAction
{
    public function execute(Profile $profile, UpstreamResponse $response): void
    {
        $likes = $response->body['likes'] ?? 0;
        $currentTime = now();

        $profile->update([
            'likes' => $likes,
            'revision' => $response->body['revision'] ?? $profile->revision,
            'last_attempted_at' => $currentTime,
            'last_synced_at' => $currentTime,
        ]);
    }
}
