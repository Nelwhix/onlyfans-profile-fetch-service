<?php

namespace App\Domain\Profile\Support;

use Illuminate\Support\Facades\Redis;

class InspectsQueueDepth
{
    public function oldestWaitingJobAgeInSeconds(string $queue = 'default'): ?int
    {
        $payloads = [
            ...Redis::lrange("queues:{$queue}", 0, -1),
            ...Redis::zrange("queues:{$queue}:delayed", 0, -1),
            ...Redis::zrange("queues:{$queue}:reserved", 0, -1),
        ];

        if ($payloads === []) {
            return null;
        }

        $now = now()->getTimestamp();

        $oldestCreatedAt = collect($payloads)
            ->map(fn (string $payload) => json_decode($payload, true)['createdAt'] ?? null)
            ->filter()
            ->min();

        if ($oldestCreatedAt === null) {
            return null;
        }

        return $now - (int) $oldestCreatedAt;
    }
}
