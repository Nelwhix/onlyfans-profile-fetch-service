<?php

namespace App\Domain\Profile\Jobs;

use App\Domain\Profile\Actions\RefreshProfileAction;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Support\OnlyfansClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

#[Tries(6)]
class NaiveRefreshProfileJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public Profile $profile) {}

    public function handle(OnlyfansClient $client, RefreshProfileAction $action): void
    {
        $response = $client->fetch($this->profile->username);

        $action->execute($this->profile, $response, $this->attempts());

        if ($response->status === null || $response->status >= 400) {
            throw new RuntimeException("Upstream request failed with status: {$response->status}");
        }
    }
}
