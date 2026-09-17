<?php

use App\Domain\Profile\Support\SimulatesUpstreamProfileResponses;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

afterEach(function () {
    foreach (['demo-test-healthy', 'demo-test-rate-limited'] as $username) {
        Cache::store('redis')->forget("demo-upstream:{$username}:behavior");
        Cache::store('redis')->forget("demo-upstream:{$username}:calls");
        Cache::store('redis')->forget("demo-upstream:{$username}:revision");
    }
});

it('defaults to healthy for an unconfigured username', function () {
    $response = app(SimulatesUpstreamProfileResponses::class)->respond('demo-test-healthy');

    expect($response->getStatusCode())->toBe(Response::HTTP_OK)
        ->and($response->getData(true))->toMatchArray(['likes' => 50_001, 'revision' => 1]);
});

it('increments the revision on each healthy call', function () {
    $simulator = app(SimulatesUpstreamProfileResponses::class);
    $simulator->configure('demo-test-healthy', SimulatesUpstreamProfileResponses::HEALTHY);

    $simulator->respond('demo-test-healthy');
    $second = $simulator->respond('demo-test-healthy');

    expect($second->getData(true))->toMatchArray(['likes' => 50_002, 'revision' => 2]);
});

it('rate limits the configured number of calls before recovering', function () {
    $simulator = app(SimulatesUpstreamProfileResponses::class);
    $simulator->configure('demo-test-rate-limited', SimulatesUpstreamProfileResponses::RATE_LIMITED_THEN_RECOVERS);

    $statuses = collect(range(1, 4))->map(fn () => $simulator->respond('demo-test-rate-limited')->getStatusCode());

    expect($statuses->all())->toBe([429, 429, 429, 200]);
});

it('resets the counters when reconfigured', function () {
    $simulator = app(SimulatesUpstreamProfileResponses::class);
    $simulator->configure('demo-test-rate-limited', SimulatesUpstreamProfileResponses::RATE_LIMITED_THEN_RECOVERS);
    $simulator->respond('demo-test-rate-limited');
    $simulator->respond('demo-test-rate-limited');

    $simulator->configure('demo-test-rate-limited', SimulatesUpstreamProfileResponses::RATE_LIMITED_THEN_RECOVERS);

    expect($simulator->respond('demo-test-rate-limited')->getStatusCode())->toBe(Response::HTTP_TOO_MANY_REQUESTS);
});
