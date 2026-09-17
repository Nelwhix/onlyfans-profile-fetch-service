<?php

namespace App\Domain\Profile\Support;

use Exception;

class CouldNotFetchProfile extends Exception
{
    public static function permanentUpstreamFailure(int $status): self
    {
        return new self("Upstream returned a permanent failure status ({$status}); not retrying.");
    }
}
