<?php

namespace App\Domain\Profile\Enums;

enum RefreshOutcome: string
{
    case Applied = 'applied';
    case Stale = 'stale';
    case Failed = 'failed';
}
