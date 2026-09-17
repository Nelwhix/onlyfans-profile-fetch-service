<?php

use App\Domain\Profile\Actions\ReportDemoWorkloadMetricsAction;
use App\Domain\Profile\Enums\RefreshOutcome;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Cache::store('redis')->forget('demo:demo-report-test:started_at');
});

it('computes successful refreshes and attempts per successful refresh', function () {
    $healthy = Profile::factory()->create(['username' => 'report-test-healthy']);
    $noisy = Profile::factory()->create(['username' => 'report-test-noisy']);

    ProfileRefreshAttempt::factory()->create(['profile_id' => $healthy->id, 'outcome' => RefreshOutcome::Applied]);
    ProfileRefreshAttempt::factory()->create(['profile_id' => $noisy->id, 'outcome' => RefreshOutcome::Failed]);
    ProfileRefreshAttempt::factory()->create(['profile_id' => $noisy->id, 'outcome' => RefreshOutcome::Failed]);
    ProfileRefreshAttempt::factory()->create(['profile_id' => $noisy->id, 'outcome' => RefreshOutcome::Applied]);

    $report = app(ReportDemoWorkloadMetricsAction::class)->execute(
        'demo-report-test',
        ['report-test-healthy', 'report-test-noisy'],
        ['report-test-healthy']
    );

    expect($report['successful_refreshes'])->toBe(2)
        ->and($report['total_attempts'])->toBe(4)
        ->and($report['attempts_per_successful_refresh'])->toBe(2.0);
});

it('returns null attempts-per-success when nothing has succeeded yet', function () {
    $profile = Profile::factory()->create(['username' => 'report-test-none']);
    ProfileRefreshAttempt::factory()->create(['profile_id' => $profile->id, 'outcome' => RefreshOutcome::Failed]);

    $report = app(ReportDemoWorkloadMetricsAction::class)->execute('demo-report-test', ['report-test-none'], []);

    expect($report['attempts_per_successful_refresh'])->toBeNull();
});

it('computes the average time for a healthy account to sync relative to when the demo started', function () {
    $this->travelTo(now());

    Cache::store('redis')->put('demo:demo-report-test:started_at', now()->subSeconds(10)->getTimestamp());

    Profile::factory()->create([
        'username' => 'report-test-timing',
        'last_synced_at' => now(),
    ]);

    $report = app(ReportDemoWorkloadMetricsAction::class)->execute(
        'demo-report-test',
        ['report-test-timing'],
        ['report-test-timing']
    );

    expect($report['average_healthy_seconds_to_sync'])->toBe(10.0);
});

it('reports the peak memory used to compute the report', function () {
    $report = app(ReportDemoWorkloadMetricsAction::class)->execute('demo-report-test', [], []);

    expect($report['peak_memory_mb'])->toBeFloat()->toBeGreaterThan(0);
});
