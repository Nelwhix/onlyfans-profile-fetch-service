# OnlyFans Profile Fetch Service

A small Laravel 13 service I built for fun to work through a production-incident scenario I designed for myself: stale/corrupted profile data plus queue starvation from one noisy account. It fetches OnlyFans profile data in the background, reproduces that incident, and fixes it. See [`AI.md`](AI.md) for how AI tools were used while building this.

## Contents

1. [Setup](#setup)
2. [A Few Deliberate Choices](#a-few-deliberate-choices)
3. [The Incident](#the-incident)
4. [Protecting the Data](#protecting-the-data)
5. [Keeping the Queue Moving](#keeping-the-queue-moving)
6. [Before / After: The Repeatable Workload](#before--after-the-repeatable-workload)
7. [Observability](#observability)
8. [Scaling to 50M Jobs/Day](#scaling-to-50m-jobsday)
9. [Production Plan](#production-plan)
10. [Remaining Limits](#remaining-limits)

## Setup

```bash
make up
```

This builds and starts everything with Docker Compose: Redis, the app (PHP-FPM + nginx), and Horizon. Migrations run automatically before the app containers start.

Every host port is non-standard on purpose, so it won't clash with anything already running on your machine: 8080 instead of 80/443 (Laravel Herd, another project's proxy, etc.), and 16379 instead of 6379 for Redis.

- App + fake upstream: **http://localhost:8080**
- Horizon dashboard: **http://localhost:8080/horizon**
- Redis: **localhost:16379**

Run any Artisan command against the stack with `make artisan ARGS="..."`, shorter than typing `docker compose exec php php artisan ...` every time:

```bash
make artisan ARGS="profiles:demo-workload --variant=real"
make artisan ARGS="profiles:demo-report --variant=real"
```

The two demo commands have their own shortcuts too:

```bash
make demo-workload VARIANT=real   # or naive
make demo-report VARIANT=real     # or naive
make demo-recovery VARIANT=real   # or naive - dispatches, waits ~60s, then reports
```

Also available: `make shell` (a shell inside the `php` container) and `make logs` (`make logs ARGS=horizon` to scope it). `make down` stops everything. Requires only Docker on the host.

### Bare-host (for iterating on the code)

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d redis     # just Redis, no other containers
php artisan migrate
php artisan serve               # serves the fake upstream at /api/profiles/{username}
php artisan horizon              # or: php artisan queue:work
```

The fake upstream (`routes/fake-upstream.php`, guarded to non-production environments) stands in for onlyfans.com. It's driven by `SimulatesUpstreamProfileResponses`, which can be configured per-username to behave normally or to rate-limit for a few calls before recovering. That's what the workload demo in section 6 uses instead of hitting the real site.

Run tests with `php artisan test --compact`, and `vendor/bin/phpstan analyse --memory-limit=512M` for static analysis. Both run against this bare-host setup (SQLite + host-mapped Redis on `127.0.0.1:16379`), not inside the Docker stack above.

Most of the suite needs Redis running, so start it first. `RefreshProfileAction`'s own tests are the exception, they don't touch Redis at all.

## A Few Deliberate Choices

**Domain-driven structure.** Everything lives under `app/Domain/Profile/` (Models, Actions, Jobs, ValueObjects, Support, Commands, Enums) instead of the default `app/Models` + `app/Http` layout. That's arguably more structure than a project this size strictly needs, but I organize by domain rather than by framework role on real projects, and wanted to show that here instead of just describing it. The conventions this implies are recorded in `.ai/rules/` as they came up.

**No `down()` methods on migrations.** Rolling back a migration that's already run against real data is rarely safe. A rollback that drops a column silently throws away whatever was written to it. I'd rather fix a bad migration with a new, corrective one than maintain a rollback path I'd be nervous to actually run.

**Memory usage is measured, not assumed.** The test guide calls out avoiding unnecessary RAM, so I built that into the logging instead of just hoping it's fine. `DispatchDueProfileRefreshesAction` uses `chunkById(500, ...)` instead of loading every due profile into memory at once, and its log line records `peak_memory_mb` each run. Each `RefreshProfileJob`'s log records `worker_memory_mb`, the same figure Horizon checks against its own memory limit, so a worker trending toward that limit shows up before Horizon has to restart it. `profiles:metrics` and `profiles:demo-report` report their own peak memory too.

## The Incident

> The oldest waiting jobs keep getting older. Workers report completed jobs, but some profiles show stale or empty data. One busy account delays the others. There has been no application deployment since the problem started.

This is two separate bugs:

| Symptom | Cause |
|---|---|
| Workers report completed jobs, but profiles show stale or empty data | A response-parsing bug: reads the wrong field, defaults a miss to zero, and marks every response as synced regardless of whether it was. |
| Oldest waiting jobs keep getting older, one busy account delays the others | No guard against dispatching a profile that's already queued or mid-retry, so a chronically failing account piles up duplicate jobs instead of the dispatch being a no-op. |

The parsing bug can't explain the queue symptoms on its own. A handler that marks every response "successful" makes jobs finish faster, since nothing gets retried. The backlog comes from the second, separate gap: nothing stops the same still-unresolved account from being dispatched again while it's already queued or retrying.

The data corruption half reproduces on the `incident/response-format-bug` branch, kept separate from `main` so the two can be diffed directly:

```bash
git checkout incident/response-format-bug
```

The buggy handler:

```php
class RefreshProfileAction
{
    public function execute(Profile $profile, UpstreamResponse $response): void
    {
        $likes = $response->body['likes'] ?? 0;
        $currentTime = now();

        $profile->update([
            'likes' => $likes,
            'revision' => $response->body['revision'] ?? $profile->revision,
            'last_attempted_at' => $currentTime,
            'last_synced_at' => $currentTime,
        ]);
    }
}
```

Three bugs:

- **Wrong location.** Reads `likes` from the top level. Once upstream moved it under `profile.likes`, every read misses and falls through to `?? 0`.
- **Zero looks valid.** That default can't be told apart from a genuine `likes: 0`, or from a response that failed outright.
- **Every response is "successful."** `last_synced_at` updates unconditionally, so a `500` with an empty body looks exactly like a real refresh.

### Evidence

Two tests assert the correct behaviour. I ran them against the buggy handler first to get red, pre-fix evidence:

```bash
make up   # this branch has its own Makefile/Docker setup too, pins the same PHP version as main
make artisan ARGS="test --filter=RefreshProfileActionTest"
```

```text
FAILED  it does not overwrite likes with zero when the response nests likes under profile
Failed asserting that 0 is identical to 121000.

FAILED  it does not erase valid data or mark a failed response as a successful refresh
Failed asserting that 0 is identical to 120000.

Tests:  2 failed
```

**Test 1:** a profile at 120,000 likes / revision 10 gets the new-format response (`profile.likes: 121000`, `revision: 11`). The buggy handler writes 0 instead of updating to 121,000.

**Test 2:** the same profile gets a `500` with an empty body. The buggy handler writes 0 to `likes` and stamps `last_synced_at` as if it succeeded.

That second one is the whole point of the incident: a completed queue job is not proof that a profile was actually refreshed.

## Protecting the Data

All of this lives in `RefreshProfileAction::execute()` on `main`, backed by `ParsedProfileResponse` (validates first) and `ProfileRefreshAttempt` (an append-only audit log, separate from the profile's current-state columns).

**Format-aware, validated parsing.** `ParsedProfileResponse::fromUpstreamResponse()` accepts both the old (`likes` at top level) and new (`profile.likes`) shapes via `data_get()`. It rejects the response entirely if the status isn't 2xx, the body is unparseable, or `likes`/`revision` is missing, negative, or non-numeric (an explicit `0` is accepted). A rejected response never reaches the database write.

**Failure never erases data.** On a parse failure, only `last_attempted_at`, `last_failure_reason`, and `next_refresh_due_at` get updated. `likes` and `revision` stay untouched, and the attempt is logged as `Failed`. This is what test 2 above locks in.

**A single atomic update guards against out-of-order writes:**

```php
Profile::query()
    ->whereKey($profile->id)
    ->where(fn ($q) => $q->whereNull('revision')->orWhere('revision', '<', $parsed->revision))
    ->update([...]);
```

If a newer revision already landed, the `WHERE` doesn't match, the update affects zero rows, and nothing changes. The attempt still gets logged as `Stale`, so a late response is visible in the audit trail without corrupting the profile. This also makes a replayed job idempotent: applying the same revision twice is a no-op the second time, not a duplicate update (`RefreshProfileJobReplayTest`).

**The profile update and its audit row commit together, or not at all.** Both writes run inside `DB::transaction()`. This is a different concern from the atomic update above, which is already safe on its own as a single statement. The transaction exists so a crash between the two writes can't leave the profile updated with no matching audit row. `RefreshProfileActionAuditTest` forces the second write to fail and checks the first one rolls back too.

**Why there's no dedicated concurrency test.** I considered adding one because I've dealt with a real concurrency bug at Oh Dear before. There, a plan limit was checked and the monitor was created in separate operations, so concurrent requests could all pass the check before any monitor was created.
Here, I avoided that race by making the revision check and update a single conditional `UPDATE`
The database only applies the update if the incoming revision is newer. So even if two workers run concurrently, an older revision cannot overwrite a newer one.
Because the protection is in the atomic operation itself, I focused the tests on stale writes being rejected and replays not overwriting newer data.


**Cadence, without re-scheduling pending work.** `next_refresh_due_at` is `+24h` for `likes > 100,000`, `+72h` otherwise (exactly 100,000 is in the 72h group). `RefreshProfileJob` also implements `ShouldBeUnique` (keyed on profile ID, 10-minute lock), so a profile already queued or in-flight can't be dispatched again by the scheduler in the meantime.

**Duplicate profiles.** Nothing here creates a `Profile` from a username at runtime, jobs only ever update one already passed to them, and every `Profile` here comes from a factory. There's no live path where two concurrent things could race to create the same profile. If one were added, `username`'s unique DB constraint (not just an app-level `firstOrCreate` convention, which has its own race window) would keep it safe.

### Tests

| Requirement | Test |
|---|---|
| Response-format bug | `RefreshProfileActionTest::it reads likes from the nested profile object...` |
| Failed request doesn't erase data / count as success | `RefreshProfileActionTest::it does not erase valid data or mark a failed response...` |
| Duplicate/replayed execution | `RefreshProfileJobReplayTest::it does not corrupt data or double-count...` |
| Older response arriving last | `RefreshProfileActionTest::it keeps the newer accepted data when an older revision arrives after` |
| Cadence (24h / 72h, 100k boundary) | `RefreshProfileActionTest::it sets the next refresh cadence...` (dataset) |
| Failing profiles rescheduled sooner | `RefreshProfileActionTest::it reschedules a failing profile much sooner...` |
| Likes/revision validation edge cases | `ParsedProfileResponseTest` (missing, zero, negative, non-numeric) |

Run: `make artisan ARGS="test --filter=RefreshProfileAction --compact"`

## Keeping the Queue Moving

`RefreshProfileJob::retryIfNeeded()` classifies every response:

| Response | Action |
|---|---|
| 2xx | Done. |
| 4xx except 429 | Fail immediately, permanent, no point retrying a client error. |
| 429 | Release with a random 5-15s delay (no `Retry-After` header to trust). |
| 5xx or no response | Release with exponential backoff: 5s, 10s, 20s, 40s, capped at 60s. |

Capped at 6 tries total.

**How the job timeout, worker timeout, and Redis `retry_after` relate.** I first thought of these as three layers, where the worker timeout would catch anything the job timeout missed. Reading `Illuminate\Queue\Worker` directly showed that's wrong:

- **Job timeout** (30s): the limit for `RefreshProfileJob` specifically.
- **Worker timeout** (60s): the fallback used only for jobs that don't set their own. Laravel picks one or the other, never both.
- **`retry_after`** (90s): how long Redis keeps a job reserved before making it available to another worker again.

So when a job times out, it doesn't get released back to the queue gracefully. The whole worker process gets killed, and if that job wasn't on its final attempt, nothing tells Redis the reservation is free. It just sits reserved until `retry_after` expires. That's why `retry_after` needs to comfortably outlast whichever timeout applies (30s here, 60s as the general fallback for anything else on this queue). If it were shorter, Redis could hand the job to a second worker while the first is still dying, and you'd get two workers on the same attempt.

**Preventing two workers on the same job** is two different problems:
- A worker dying without releasing its reservation: solved by the timeout relationship above.
- The same profile dispatched twice (scheduler or a duplicate trigger): solved by `ShouldBeUnique` + `#[UniqueFor(600)]`, enforced by the dispatcher itself before the job reaches the queue, and it persists through retries.

**Replaying a job after its DB write.** `RefreshProfileJobReplayTest` runs `handle()` to completion, then runs it again on the same profile with the same response, simulating a worker that died after writing but before the queue recorded it. The second run must not double-count or corrupt data.

What this proves: the write path is idempotent. What it doesn't prove: anything about a worker crashing mid-write, Redis's own delivery mechanics, or concurrent (not sequential) redelivery. It's a same-process, sequential replay, a real crash-and-redeliver involves process death and the queue driver's own timing, neither of which this test exercises.

## Before / After: The Repeatable Workload

`profiles:demo-workload --variant={real|naive}` dispatches a fixed workload: one noisy account returning `429` for its first 3 calls before recovering, plus three healthy accounts that always succeed. `real` uses the fixed `RefreshProfileJob`. `naive` uses `NaiveRefreshProfileJob`, a deliberately unfixed comparison job with no uniqueness guard and no backoff, kept only for this comparison. Each run clears the queue and resets its own demo data first, so switching variants (or re-running the same one) mid-demo is always safe.

The job count and delays are deliberately small, chosen to finish in well under a minute on one machine, not to represent production volume.

```bash
make up
make demo-workload VARIANT=naive   # or VARIANT=real
make demo-report VARIANT=naive     # or VARIANT=real
```

`naive` has no `ShouldBeUnique`, so the noisy account's burst is 5 separate dispatches, all queued alongside the 3 healthy ones. `real` collapses that to 1. Numbers from a local run:

| Metric | naive | real |
|---|---|---|
| **Jobs waiting after dispatch** | **8** | **4** |
| Successful refreshes | 8 | 4 |
| Total attempts | 11 | 7 |
| Attempts per successful refresh | 1.38 | 1.75 |
| Oldest waiting job after one drain pass | Queue empty | 4s |
| Avg. time for healthy account to sync | 11s | 1s |

The clearest difference is the first row. Both versions process the same accounts, but the naive version allows the failing account to be dispatched repeatedly while it is already waiting or retrying. The real version prevents those duplicate jobs.

In a real scheduler running every 5 minutes, this would keep adding duplicate work for an account that never recovers, while the real version would dispatch it once and wait for it to finish or recover.
Two things worth calling out honestly:


**Attempts-per-success is worse for `real` (1.75 vs 1.38), and that's expected.** `naive`'s ratio only looks better because 5 redundant dispatches inflate both sides of the fraction. `real`'s 7 attempts are 4 real accounts refreshed with only the retries the noisy account genuinely needed, no duplicate work at all.

### Recovery when the rate limit clears

To show recovery when the rate limit clears, run:

```bash
make demo-recovery VARIANT=real   # or naive
```

That dispatches, waits ~60s, then reports:

`demo-noisy` recovers: synced, no failure reason, indistinguishable from the healthy accounts. `naive` recovers too (it ends on revision 5 instead of 1, since its 5 duplicate dispatches each independently called the upstream, a visible trace of the exact duplicate-dispatch problem this comparison is about).

## Observability

- `RefreshProfileJob` logs `profile.refresh.attempt` on every attempt: account, profile ID, job ID, attempt number, HTTP status, the retry decision, and the worker's current memory usage. Enough to trace one account/profile/job through a failure and its recovery.
- `DispatchDueProfileRefreshesAction` logs `profile.dispatch.scheduled` with a per-tick dispatched count and peak memory.
- `profiles:metrics`, a general-purpose health check: total profiles, currently overdue, currently failing, synced in the last 24h, oldest waiting job age, and its own peak memory.
- No secrets are logged, usernames and IDs only, never response bodies.

## Scaling to 50M Jobs/Day

**What I'd measure next, before changing anything:**
- Peak arrivals, not just average. A scheduler dispatching in chunks every 5 minutes produces spikes, not a smooth 579/s.
- Job duration distribution (p50/p95/p99). A handful of slow accounts can dominate worker time even at low job counts.
- Concurrent work actually achievable: worker count × (1 / avg job duration), against the required throughput.
- Per-worker PHP memory footprint at that worker count, not whether one worker fits in memory, but whether hundreds do. This is a real failure mode I've hit at Oh dear: PHP workers running a similar hot path (pull a job, make a request, publish the result) cost about 44.5MB each. Fine individually, but 850 of them to hit the needed concurrency was 37.8GB. Rewriting just that hot path in Go, 250 goroutines instead of 850 processes, cut it to about 72MB total, roughly 500x more efficient for the same throughput. The overhead isn't the job logic, it's the fixed cost of a full process per worker, and it doesn't show up until worker count gets large.
- Retry amplification: what fraction of "jobs" are actually retries, and whether that grows under upstream stress.
- Database write pressure: `profile_refresh_attempts` is insert-only and unbounded, at 50M/day that's 50M new rows a day.
- Redis memory and per-op latency under real queue depth, not the empty queue this demo runs against.
- Upstream's actual rate limits, unknown today. The fake upstream's 429 behaviour is a stand-in, not a measurement.
- The largest accounts specifically: a few very active accounts could dominate load the way the noisy account did above, just permanently.

**Likely bottleneck in this solution specifically:** `profile_refresh_attempts`, an unbounded, unpartitioned, insert-only table with no retention policy. At 50M attempts/day it's the fastest-growing table in the system, and every insert competes with the profile-update transaction on the same connection pool. The single `default` Redis queue is the second candidate, every job shares one queue today, so a very active account still competes with everyone else for the same worker pool.

**Evidence I'd collect:** attempt-table row growth vs. disk/IO over a real day, `profiles:metrics`'s oldest-waiting-job-age trended over time, and per-account attempt counts to check whether load actually concentrates the way this demo assumes.

**First change I'd make:** partition `profile_refresh_attempts` by time, and split the single `default` queue into per-tier queues (by account size or failure history) so one tier's backlog can't starve another's, the same isolation principle as `ShouldBeUnique`, applied at the queue level.

That fixes this solution's actual bottleneck (DB writes and queue contention), not worker memory, that's not where the evidence points given this job's small payload. If it later did, the Go precedent above is the concrete next step: keep the validation and write logic in PHP, move just the fetch-and-report hot path into a lightweight worker pool. Not a rewrite of the whole service.

## Production Plan

**First 15 minutes:**
- Check `profiles:metrics` for oldest-waiting-job-age and currently-failing count, confirms whether the incident is still active.
- Check Horizon's dashboard for overall queue backup (wait time, throughput). It only breaks down by job class and queue, not by account, there's no dashboard view for "which account is the source." For that, query `profile_refresh_attempts` grouped by `profile_id` for a disproportionate attempt count.
- Don't trust "jobs completed" as a health signal. Cross-check the outcome distribution in `profile_refresh_attempts` for a spike in `Stale` or `Failed`, and staleness on high-value profiles.
- If one account is confirmed as the source, pause/hold its jobs at the queue level without touching the fix, buying time to deploy calmly.

**Rollout:** deploy as-is, no feature flag needed. `ShouldBeUnique` is enforced at dispatch time, so partially-rolled-out workers and the scheduler agree on the same lock immediately. Watch dispatch/attempt log volume right after deploy for the expected drop in duplicate work.

**Rollback trigger:** `currently_failing` or oldest-waiting-job-age rising after the deploy, or the outcome mix shifting unexpectedly (a spike in `Stale` would mean the revision guard is rejecting valid data, a regression, not the fix working).

**Verifying recovery:** oldest-waiting-job-age trending back down, `synced_last_24h` returning to normal, and spot-checking a few previously-stale profiles against what the upstream currently reports.

## Remaining Limits

- The demo workload is small and fast by design. It proves the mechanism, not throughput at scale (see [Scaling](#scaling-to-50m-jobsday)).
- `profile_refresh_attempts` has no retention or pruning, fine at demo scale, a real problem at production scale.
- The replay test proves write-path idempotency, not real worker-crash redelivery timing (see the caveat in [Keeping the Queue Moving](#keeping-the-queue-moving)).
- No search (Laravel Scout) and no UI beyond the fake-upstream demo routes — deliberately out of scope; the profile-refresh mechanics were the interesting part to build.
- There's no way to add a new username to track at runtime, every `Profile` comes from a factory. The duplicate-profile-creation guarantee is real and the DB constraint enforces it, but there's no live path to demonstrate it against.
- `OnlyfansClient` is a plain API client. A real integration would need session/cookie handling, proxy rotation, and anti-bot handling, none of which is in scope here.
