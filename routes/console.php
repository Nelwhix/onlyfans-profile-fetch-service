<?php

use App\Domain\Profile\Commands\DispatchDueProfileRefreshesCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command(DispatchDueProfileRefreshesCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();
