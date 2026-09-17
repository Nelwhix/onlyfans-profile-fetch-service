<?php

namespace App\Domain\Profile\Jobs;

use App\Domain\Profile\Actions\RefreshProfileAction;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Support\CouldNotFetchProfile;
use App\Domain\Profile\Support\OnlyfansClient;
use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

#[Timeout(30)]
#[Tries(6)]
#[UniqueFor(600)]
class RefreshProfileJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const int RATE_LIMIT_MIN_DELAY_SECONDS = 5;

    public const int RATE_LIMIT_MAX_DELAY_SECONDS = 15;

    public const int BACKOFF_BASE_SECONDS = 5;

    private const int BACKOFF_MAX_SECONDS = 60;

    private const int FAILURE_RETRY_DELAY_MINUTES = 30;

    public function __construct(public Profile $profile) {}

    public function uniqueId(): string
    {
        return $this->profile->id;
    }

    public function handle(OnlyfansClient $client, RefreshProfileAction $action): void
    {
        $response = $client->fetch($this->profile->username);

        $action->execute($this->profile, $response, $this->attempts());

        $decision = $this->retryIfNeeded($response);

        Log::info('profile.refresh.attempt', [
            'account' => $this->profile->username,
            'profile_id' => $this->profile->id,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'http_status' => $response->status,
            'decision' => $decision,
            'worker_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $now = CarbonImmutable::now();

        $this->profile->update([
            'last_attempted_at' => $now,
            'last_failure_reason' => $exception?->getMessage() ?? 'Job failed after exhausting all retry attempts.',
            'next_refresh_due_at' => $now->addMinutes(self::FAILURE_RETRY_DELAY_MINUTES),
        ]);

        Log::error('profile.refresh.failed', [
            'account' => $this->profile->username,
            'profile_id' => $this->profile->id,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->attempts(),
            'exception' => $exception?->getMessage(),
        ]);
    }

    private function retryIfNeeded(UpstreamResponse $response): string
    {
        if ($response->status !== null && $response->status < 400) {
            return 'completed';
        }

        // covers all failure codes except too many requests
        if ($response->status !== null && $response->status < 500 && $response->status !== Response::HTTP_TOO_MANY_REQUESTS) {
            $this->fail(CouldNotFetchProfile::permanentUpstreamFailure($response->status));

            return 'failed_permanently';
        }

        if ($response->status === Response::HTTP_TOO_MANY_REQUESTS) {
            $delay = random_int(self::RATE_LIMIT_MIN_DELAY_SECONDS, self::RATE_LIMIT_MAX_DELAY_SECONDS);
            $decision = 'retrying_rate_limited';
        } else {
            // Exponential backoff, doubling each attempt: 5s, 10s, 20s, 40s...
            // capped at BACKOFF_MAX_SECONDS so it never grows unbounded.
            $delay = (int) min(self::BACKOFF_BASE_SECONDS * (2 ** ($this->attempts() - 1)), self::BACKOFF_MAX_SECONDS);
            $decision = 'retrying_backoff';
        }

        $this->release($delay);

        return $decision;
    }
}
