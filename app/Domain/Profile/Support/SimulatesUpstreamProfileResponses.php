<?php

namespace App\Domain\Profile\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class SimulatesUpstreamProfileResponses
{
    public const string HEALTHY = 'healthy';

    public const string RATE_LIMITED_THEN_RECOVERS = 'rate_limited_then_recovers';

    private const int RATE_LIMITED_ATTEMPTS_BEFORE_RECOVERY = 3;

    public function configure(string $username, string $behaviour): void
    {
        // sets which behaviour a username should simulate and resets its counters
        $this->store()->put($this->key($username, 'behaviour'), $behaviour);
        $this->store()->forget($this->key($username, 'calls'));
        $this->store()->forget($this->key($username, 'revision'));
    }

    public function respond(string $username): JsonResponse
    {
        $behaviour = $this->store()->get($this->key($username, 'behaviour'), self::HEALTHY);

        if ($behaviour === self::RATE_LIMITED_THEN_RECOVERS) {
            $calls = $this->store()->increment($this->key($username, 'calls'));

            if ($calls <= self::RATE_LIMITED_ATTEMPTS_BEFORE_RECOVERY) {
                return response()->json(null, Response::HTTP_TOO_MANY_REQUESTS);
            }
        }

        $revision = $this->store()->increment($this->key($username, 'revision'));

        return response()->json([
            'likes' => 50_000 + $revision,
            'revision' => $revision,
        ]);
    }

    private function store(): Repository
    {
        return Cache::store('redis');
    }

    private function key(string $username, string $suffix): string
    {
        return "demo-upstream:{$username}:{$suffix}";
    }
}
