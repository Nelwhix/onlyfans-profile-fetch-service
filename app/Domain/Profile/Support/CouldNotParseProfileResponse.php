<?php

namespace App\Domain\Profile\Support;

use Exception;

class CouldNotParseProfileResponse extends Exception
{
    public static function noResponse(): self
    {
        return new self('No response was received from upstream.');
    }

    public static function unsuccessfulStatus(int $status): self
    {
        return new self("Upstream returned an unsuccessful status: {$status}.");
    }

    public static function unparseableBody(): self
    {
        return new self('The response body was empty or not valid JSON.');
    }

    public static function invalidLikes(mixed $value): self
    {
        return new self('The likes value is missing, non-numeric, or negative: '.json_encode($value));
    }

    public static function invalidRevision(mixed $value): self
    {
        return new self('The revision value is missing, non-numeric, or negative: '.json_encode($value));
    }
}
