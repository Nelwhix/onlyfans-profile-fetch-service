# AI Usage

I used Claude Code as the main coding agent for this project. I gave direction, reviewed the implementation, and checked the decisions it made. For most of the build I asked Claude to write the code, then reviewed and discussed each change before moving on. I can walk through and explain every file and decision in this repository.

## Tools used

- **Claude Code**: main coding agent.
- **Laravel Boost MCP**: used `search-docs`, `database-schema`, and Tinker to verify Laravel 13 and Pest behaviour against the version actually installed, rather than assuming.

## Mistakes I caught and how I fixed them

### 1. Wrong assumption about Redis retries

I assumed a noisy account's retries would jump ahead of other queued jobs, which is why account isolation seemed necessary. A manual test showed identical metrics for the naive and fixed implementations, which didn't fit. I had Claude read `Illuminate\Queue\RedisJob::release()` and `RedisQueue::deleteAndRelease()` directly. Released jobs go to the back of the list even with a 0s delay, so retries never jump the queue. The real isolation problem is duplicate dispatches for the same account, not retry order. Fixed the explanation and the demo accordingly.

### 2. A concurrency test that didn't test the application

Claude's first attempt at proving the revision guard was race-safe added a Postgres container and a test using raw `pg_connect()` calls. I rejected it, it was testing two raw SQL statements, not any Laravel code path. Asking why this needed a concurrency test at all surfaced the real distinction: the guard is a single atomic `UPDATE`, so there's no gap between checking a value and writing it for application code to get wrong. Removed the Postgres/concurrency infrastructure and kept sequential tests of the guard's actual behaviour.

### 3. Following the tech list past the actual scope

I sketched out Scout, Redis, and Horizon in my head early on, so the initial scaffold installed Scout and pulled in a full Livewire/Fortify auth UI. Then I reminded myself I wanted to keep the app small, no dashboard, search, or full API needed. Removed the auth UI early. Removed Scout later, once I confirmed nothing in the app actually used it.

### 4. Failed refreshes rescheduled too far out

The first version set `next_refresh_due_at` after a failure using the profile's normal cadence, so a failing profile could go up to three days before being rechecked. I handle failed uptime checks the same way at Oh Dear: retry sooner than the healthy cadence, not on it. Now a failed refresh is rescheduled 30 minutes out regardless of cadence.

### 5. A cache behaviour I only caught by running it

`Cache::store('database')->increment()` on a fresh key returned `false` instead of initialising to `1`. I confirmed this with a Tinker `var_dump()` rather than trusting what I expected, and switched the demo's rate-limit simulator to the Redis store explicitly.

### 6. The incident writeup mixed two different problems

My first pass treated the "marks everything successful" bug as the explanation for all three symptoms I'd designed into the incident, including the growing queue backlog. Re-reading it: a handler that marks every response successful makes jobs finish faster, not slower, since nothing gets retried. It can't explain a growing backlog. The incident is actually two separate bugs: the response-parsing bug, and a missing per-profile dispatch guard that lets a chronically failing account pile up duplicate jobs. The second was already fixed with `ShouldBeUnique` but never clearly shown. Fixed the evidence by having the demo action report queue size immediately after dispatch: naive leaves 8 jobs waiting, real leaves 4. Added a symptom-to-cause table to the README so the two bugs aren't left for the reader to figure out.

### 7. Missing transaction around the audit trail

Asking what happens if the second database write fails surfaced a real gap: `RefreshProfileAction` updates the profile and writes an audit row as separate statements, so a crash between them could leave one without the other. This doesn't touch the revision guard, that's already a single atomic statement, it's specifically about the profile and its audit trail staying consistent. Fixed by wrapping both writes in `DB::transaction()`, and verified properly: the new test forces the audit write to fail and checks the profile update rolled back too, confirmed red against the pre-fix code before trusting it green against the fix.

## What I have not verified

**Real OnlyFans upstream behaviour.** Everything here runs against a local fake upstream built to match the response shapes I designed the incident around. I haven't verified the real site's actual error codes, headers, rate limits, or anti-bot behaviour. `OnlyfansClient` is a plain API client, not a production-ready integration, a real one would need session/cookie handling, proxy rotation, and anti-bot handling.

**Real worker crashes and redelivery.** The replay test proves the write path is idempotent under a same-process, sequential replay. It doesn't exercise an actual process crash mid-write, or Redis's own reservation/redelivery timing after a real failure.

**Production-scale throughput.** The workload demo is deliberately small and fast, by design. It shows the specific incident mechanisms are fixed at small scale, not that the system handles 50 million jobs a day. Before making that claim I'd run a real load test and measure queue latency, worker throughput, Redis load, database pressure, and retry behaviour at the target scale.
