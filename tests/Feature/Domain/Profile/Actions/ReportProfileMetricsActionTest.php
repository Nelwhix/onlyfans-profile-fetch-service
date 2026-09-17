<?php

use App\Domain\Profile\Actions\ReportProfileMetricsAction;
use App\Domain\Profile\Models\Profile;

it('counts every profile regardless of state', function () {
    Profile::factory()->count(3)->create();

    $report = app(ReportProfileMetricsAction::class)->execute();

    expect($report['total_profiles'])->toBe(3);
});

it('counts a profile as overdue once its due date has passed', function () {
    Profile::factory()->create(['next_refresh_due_at' => now()->subMinute()]);
    Profile::factory()->create(['next_refresh_due_at' => now()->addHour()]);

    $report = app(ReportProfileMetricsAction::class)->execute();

    expect($report['currently_overdue'])->toBe(1);
});

it('counts a profile as failing only when it carries a failure reason', function () {
    Profile::factory()->create(['last_failure_reason' => 'unparseable_body']);
    Profile::factory()->create(['last_failure_reason' => null]);

    $report = app(ReportProfileMetricsAction::class)->execute();

    expect($report['currently_failing'])->toBe(1);
});

it('counts a profile as synced only within the last 24 hours', function () {
    Profile::factory()->create(['last_synced_at' => now()->subHours(23)]);
    Profile::factory()->create(['last_synced_at' => now()->subHours(25)]);
    Profile::factory()->create(['last_synced_at' => null]);

    $report = app(ReportProfileMetricsAction::class)->execute();

    expect($report['synced_last_24h'])->toBe(1);
});

it('reports the peak memory used to compute the report', function () {
    $report = app(ReportProfileMetricsAction::class)->execute();

    expect($report['peak_memory_mb'])->toBeFloat()->toBeGreaterThan(0);
});
