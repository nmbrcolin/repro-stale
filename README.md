# repro-stale

## Overview

This repo contains a minimal reproduction of what appears to be a job loss bug introduced in 
`laravel/framework` 13.5.0. It's based on production code that's been working properly for >1
year, through many framework upgrades, and that started failing after upgrading to 13.5.0.
While I haven't pinpointed the exact bug, the failure is easily reproducible.

## Running the test

`run-test.sh` runs the test with both 13.4.0 (passes) and 13.5.0 (fails).

Environment variables for tuning:

* `PARALLEL`: parallel hammer workers (default 4)
* `DURATION`: wall-clock seconds each hammer runs for (default 60)
* `WIDGETS`: distinct widgets to dispatch against (default 10)
* `DEADLINE`: seconds the queue must stay idle before verdict (default 5)
* `SLEEP_MIN`: lower bound on per-dispatch sleep (milliseconds; default 10)
* `SLEEP_MAX`: upper bound on per-dispatch sleep (milliseconds; default 2000)

## How it works

The repro uses a single `Widget` model with a nullable `stale_since` timestamp, plus three
artisan commands:

* `repro:seed`: Wipes the `widgets` table and creates new records for a new test run.

* `repro:hammer`: Repeatedly picks a random widget, sets `stale_since=now()` on it, and 
  dispatches a `WidgetJob` keyed on the widget's id. Sleeps a random amount of time between 
  iterations. Multiple hammers run in parallel for contention.

* `repro:watch`: Repeatedly polls Redis and the database once per second, printing the 
  number of jobs in the queue and the number of stale widgets. Once the queue has been
  observed non-empty, and then drained to zero, the watcher emits a verdict: exit 0 if no
  widgets have `stale_since` set, exit 1 (`BUG REPRODUCED`) if any do.

`WidgetJob` is `ShouldBeUniqueUntilProcessing` with `$uniqueFor = 300` and `$tries = 5`,
and registers a `WithoutOverlapping` middleware keyed on the widget id with a jittered
backoff (1, 5, 15, 30s). Its `handle()` sleeps 100ms–10s to simulate work, then clears
`stale_since` on the widget.

The contract being tested is that every staled widget must eventually become un-staled.
The job dispatched by a given `setStale + dispatch` pair may ultimately not be run - it
may be dropped by `ShouldBeUniqueUntilProcessing` because another job is already in queue
for that widget - but in that case the job in the queue would ultimately un-stale the 
widget. If `stale_since` is still set on any widget after the queue has fully drained -
with no failed jobs and no exceptions - a job was lost.

`run-test.sh` orchestrates this per framework version: 

* `composer require`s the version of `laravel/framework`
* wipes Redis and the database
* restarts Horizon
* seeds widgets
* launches parallel hammers
* runs the watcher in the foreground.

## Sample output

```
# Bringing up stack

============================================================
VERSION: laravel/framework=13.4.0
============================================================
# Updating dependency to laravel/framework:13.4.0
# Wiping database and queue
# Restarting horizon
# Seeding 10 widget(s)
# Launching 4 hammers x 60s (sleep [10, 2000]ms) + watcher in parallel
# Watching for stuck stale_since…
queue=0 widgets_stale=3
queue=4 widgets_stale=4
queue=7 widgets_stale=7
queue=8 widgets_stale=7
queue=10 widgets_stale=8
...
queue=2 widgets_stale=0
queue=1 widgets_stale=0
queue=1 widgets_stale=0
queue=1 widgets_stale=0
queue=0 widgets_stale=0
...
queue=0 widgets_stale=0
# PASSED: Queue was idle for 15s with no stuck stale_since.
============================================================
framework13.4.0 PASSED
============================================================

============================================================
VERSION: laravel/framework=13.5.0
============================================================
# Updating dependency to laravel/framework:13.5.0
# Wiping database and queue
# Restarting horizon
# Seeding 10 widget(s)
# Launching 4 hammers x 60s (sleep [10, 2000]ms) + watcher in parallel
# Watching for stuck stale_since…
queue=0 widgets_stale=0
queue=4 widgets_stale=4
queue=7 widgets_stale=6
queue=11 widgets_stale=8
queue=14 widgets_stale=9
...
queue=1 widgets_stale=8
queue=1 widgets_stale=9
queue=1 widgets_stale=9
queue=1 widgets_stale=9
queue=0 widgets_stale=10
...
queue=0 widgets_stale=10
# FAILED: Queue was idle 15s, but 10 widget(s) still stale.
+----------------------------+---------------------+---------------------+
| id                         | stale_since         | updated_at          |
+----------------------------+---------------------+---------------------+
| 01kr1nyp6ezk2tmkjr3en4cdq4 | 2026-05-07 16:58:09 | 2026-05-07 16:58:09 |
| 01kr1nyp6tjmrj79xtsd6pe1hj | 2026-05-07 16:58:08 | 2026-05-07 16:58:08 |
| 01kr1nyp6z3zav9q1t54e7njkc | 2026-05-07 16:58:12 | 2026-05-07 16:58:12 |
| 01kr1nyp74ge3wq145g914nsfk | 2026-05-07 16:58:15 | 2026-05-07 16:58:15 |
| 01kr1nyp774qspq12e16ze7qz9 | 2026-05-07 16:58:11 | 2026-05-07 16:58:11 |
| 01kr1nyp7b6b6gytdexe9092jb | 2026-05-07 16:58:14 | 2026-05-07 16:58:14 |
| 01kr1nyp7dm69yfnw1d8yzhp88 | 2026-05-07 16:58:08 | 2026-05-07 16:58:08 |
| 01kr1nyp7fjqx42bf4agtmzbnf | 2026-05-07 16:58:14 | 2026-05-07 16:58:14 |
| 01kr1nyp7gwwkccg3px5nmn7ng | 2026-05-07 16:58:13 | 2026-05-07 16:58:13 |
| 01kr1nyp7jf5b991vwa1etnw48 | 2026-05-07 16:58:14 | 2026-05-07 16:58:14 |
+----------------------------+---------------------+---------------------+
============================================================
framework13.5.0 FAILED
============================================================
```
