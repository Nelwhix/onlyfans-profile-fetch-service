<?php

namespace App\Domain\Profile\Actions;

use App\Domain\Profile\Jobs\NaiveRefreshProfileJob;
use App\Domain\Profile\Jobs\RefreshProfileJob;
use App\Domain\Profile\Models\Profile;
use App\Domain\Profile\Models\ProfileRefreshAttempt;
use App\Domain\Profile\Support\SimulatesUpstreamProfileResponses;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

class DispatchDemoWorkloadAction
{
    public const string NOISY_USERNAME = 'demo-noisy';

    /** @var array<int, string> */
    public const array HEALTHY_USERNAMES = ['demo-healthy-1', 'demo-healthy-2', 'demo-healthy-3'];

    private const int NOISY_BURST_SIZE = 5;

    public function __construct(private readonly SimulatesUpstreamProfileResponses $simulator) {}

    /**
     * Dispatches the workload and returns how many jobs are sitting in the
     * queue immediately afterward, before any worker has run - the burst
     * models a still-unresolved profile getting dispatched again on top of
     * itself (e.g. a periodic scheduler re-checking it before its first
     * attempt has even run), so this count is the direct evidence for
     * whether one account can pile up disproportionate backlog.
     */
    public function execute(string $variant): int
    {
        $jobClass = $variant === 'naive' ? NaiveRefreshProfileJob::class : RefreshProfileJob::class;

        // Clears jobs from the previous demo run
        Artisan::call('queue:clear', ['connection' => 'redis', '--queue' => 'default']);

        Cache::store('redis')->put("demo:{$variant}:started_at", now()->getTimestamp());

        $this->simulator->configure(self::NOISY_USERNAME, SimulatesUpstreamProfileResponses::RATE_LIMITED_THEN_RECOVERS);
        $noisyProfile = $this->resetProfile(self::NOISY_USERNAME);

        for ($i = 0; $i < self::NOISY_BURST_SIZE; $i++) {
            $jobClass::dispatch($noisyProfile);
        }

        foreach (self::HEALTHY_USERNAMES as $username) {
            $this->simulator->configure($username, SimulatesUpstreamProfileResponses::HEALTHY);
            $jobClass::dispatch($this->resetProfile($username));
        }

        return Queue::size();
    }

    private function resetProfile(string $username): Profile
    {
        $existing = Profile::where('username', $username)->first();

        if ($existing) {
            ProfileRefreshAttempt::where('profile_id', $existing->id)->delete();
            $existing->delete();
        }

        return Profile::factory()->create([
            'username' => $username,
            'likes' => 0,
            'revision' => null,
        ]);
    }
}
