<?php

use App\Domain\Profile\Support\OnlyfansClient;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

it('parses an old-format success response with top-level likes', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => 'tests/Fixtures/profile-old-format-success.json'
                |> base_path(...)
                |> file_get_contents(...)
                |> Http::response(...),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBe(Response::HTTP_OK)
        ->and($response->body)->toBe(['likes' => 120_000, 'revision' => 10]);
});

it('parses a new-format success response with nested profile likes', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => 'tests/Fixtures/profile-new-format-success.json'
                |> base_path(...)
                |> file_get_contents(...)
                |> Http::response(...),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBe(Response::HTTP_OK)
        ->and($response->body)->toBe(['profile' => ['likes' => 121_000], 'revision' => 11]);
});

it('passes through a 429 status without altering the body', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response(['error' => 'too many requests'], Response::HTTP_TOO_MANY_REQUESTS),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBe(Response::HTTP_TOO_MANY_REQUESTS)
        ->and($response->body)->toBe(['error' => 'too many requests']);
});

it('returns a null body for a server error with an empty body', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response('', Response::HTTP_INTERNAL_SERVER_ERROR),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBe(Response::HTTP_INTERNAL_SERVER_ERROR)
        ->and($response->body)->toBeNull();
});

it('returns a null body when a 200 response is not valid JSON', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::response('<html>not json</html>'),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBe(Response::HTTP_OK)
        ->and($response->body)->toBeNull();
});

it('returns a null status and null body when the connection fails', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('services.onlyfans.base_url').'/api/profiles/madison420ivy' => Http::failedConnection(),
    ]);

    $response = app(OnlyfansClient::class)->fetch('madison420ivy');

    expect($response->status)->toBeNull()
        ->and($response->body)->toBeNull();
});
