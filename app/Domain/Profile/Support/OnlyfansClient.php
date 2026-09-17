<?php

namespace App\Domain\Profile\Support;

use App\Domain\Profile\ValueObjects\UpstreamResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;

class OnlyfansClient
{
    public function __construct(private readonly PendingRequest $request) {}

    public function fetch(string $username): UpstreamResponse
    {
        try {
            $response = $this->request->get("/api/profiles/{$username}");
        } catch (ConnectionException) {
            return UpstreamResponse::failed();
        }

        $decoded = json_decode($response->body(), true);

        // you can't trust a downstream service, so I check that the returned json payload could even be parsed.
        $body = json_last_error() === JSON_ERROR_NONE ? $decoded : null;

        return new UpstreamResponse($response->status(), $body);
    }
}
