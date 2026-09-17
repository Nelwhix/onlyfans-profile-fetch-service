<?php

namespace App\Domain\Profile\ValueObjects;

class UpstreamResponse
{
    /**
     * @param  ?int  $status  HTTP status code. Null means no response was received at all (timeout, DNS failure, connection refused).
     * @param  array<string, mixed>|null  $body  Parsed response body. Null means unparseable, not a stand-in for a genuinely empty parsed payload.
     */
    public function __construct(
        public ?int $status,
        public ?array $body,
    ) {}

    public static function failed(?int $status = null): self
    {
        return new self($status, null);
    }
}
