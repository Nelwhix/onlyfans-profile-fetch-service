<?php

use App\Domain\Profile\Support\InspectsQueueDepth;
use Illuminate\Support\Facades\Redis;

afterEach(function () {
    Redis::del('queues:test-queue-depth', 'queues:test-queue-depth:delayed', 'queues:test-queue-depth:reserved');
});

it('returns null when the queue is empty', function () {
    expect(app(InspectsQueueDepth::class)->oldestWaitingJobAgeInSeconds('test-queue-depth'))->toBeNull();
});

it('returns the age of the oldest job across ready, delayed, and reserved states', function () {
    $now = now()->getTimestamp();

    Redis::rpush('queues:test-queue-depth', json_encode(['createdAt' => $now - 10]));
    Redis::zadd('queues:test-queue-depth:delayed', $now + 5, json_encode(['createdAt' => $now - 45]));
    Redis::zadd('queues:test-queue-depth:reserved', $now + 90, json_encode(['createdAt' => $now - 3]));

    expect(app(InspectsQueueDepth::class)->oldestWaitingJobAgeInSeconds('test-queue-depth'))->toBe(45);
});
