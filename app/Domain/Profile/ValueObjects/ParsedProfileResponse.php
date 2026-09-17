<?php

namespace App\Domain\Profile\ValueObjects;

use App\Domain\Profile\Support\CouldNotParseProfileResponse;

class ParsedProfileResponse
{
    public function __construct(
        public int $likes,
        public int $revision,
    ) {}

    public static function fromUpstreamResponse(UpstreamResponse $response): self
    {
        if ($response->status === null) {
            throw CouldNotParseProfileResponse::noResponse();
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw CouldNotParseProfileResponse::unsuccessfulStatus($response->status);
        }

        if ($response->body === null) {
            throw CouldNotParseProfileResponse::unparseableBody();
        }

        $likes = data_get($response->body, 'likes') ?? data_get($response->body, 'profile.likes');

        if (! is_numeric($likes) || $likes < 0) {
            throw CouldNotParseProfileResponse::invalidLikes($likes);
        }

        $revision = data_get($response->body, 'revision');

        if (! is_numeric($revision) || $revision < 0) {
            throw CouldNotParseProfileResponse::invalidRevision($revision);
        }

        return new self((int) $likes, (int) $revision);
    }
}
