#!/usr/bin/env bash

# Run the repro against laravel/framework 13.4.0 vs 13.5.0 and record
# a verdict per case. Each case:
#
#   1. composer requires the pinned framework version inside the app container
#   2. restarts horizon so it loads the new code
#   3. wipes database + redis, re-seed widgets
#   4. launches $PARALLEL hammers (each $ITERATIONS dispatches)
#   5. runs the watcher; its exit code is the case verdict
#         0 = CLEAN, 1 = REPRODUCED
#
# Environment variables for tuning:
#   PARALLEL       parallel hammer workers per case                  (default 4)
#   DURATION       wall-clock seconds each hammer runs               (default 60)
#   WIDGETS        distinct widgets to dispatch against              (default 10)
#   DEADLINE       seconds the queue must stay idle before verdict   (default 5)
#   SLEEP_MIN      lower bound on per-dispatch sleep (milliseconds)  (default 10)
#   SLEEP_MAX      upper bound on per-dispatch sleep (milliseconds)  (default 2000)
#
# Usage:
#   ./run-test.sh
#   PARALLEL=8 DURATION=300 ./run-test.sh

PARALLEL="${PARALLEL:-4}"
DURATION="${DURATION:-60}"
WIDGETS="${WIDGETS:-10}"
DEADLINE="${DEADLINE:-15}"
SLEEP_MIN="${SLEEP_MIN:-10}"
SLEEP_MAX="${SLEEP_MAX:-2000}"

FRAMEWORK_VERSIONS=(
  "13.4.0"   # baseline
  "13.5.0"   # suspect
)

SUMMARY=""

echo "# Bringing up stack"
docker compose up -d app horizon >/dev/null 2>&1

test_version() {
  local version="$1"
  local label="framework${version}"

  echo
  echo "============================================================"
  echo "VERSION: laravel/framework=${version}"
  echo "============================================================"

  echo "# Updating dependency to laravel/framework:$version"
  docker compose exec -T app composer require \
      "laravel/framework:$version" \
      --update-with-all-dependencies --no-interaction --no-progress >/dev/null 2>&1

  echo "# Wiping database and queue"
  docker compose exec -T app php artisan migrate:fresh --force >/dev/null 2>&1
  docker compose exec -T redis redis-cli FLUSHALL >/dev/null 2>&1

  # Restart horizon AFTER wiping redis so it bootstraps its supervisor
  # bookkeeping against a clean store. Doing the flush after the restart
  # leaves horizon with a live master process but no working consumers.
  # Give it a few seconds to restart.
  echo "# Restarting horizon"
  docker compose restart horizon >/dev/null 2>&1
  sleep 5

  echo "# Seeding $WIDGETS widget(s)"
  docker compose exec -T app php artisan repro:seed --widgets="$WIDGETS" >/dev/null 2>&1

  echo "# Launching $PARALLEL hammers x ${DURATION}s (sleep [${SLEEP_MIN}, ${SLEEP_MAX}]ms) + watcher in parallel"

  # Hammers run in the background, output silenced.
  for i in $(seq 1 "$PARALLEL"); do
    docker compose exec -T app php artisan repro:hammer \
        --duration="$DURATION" \
        --sleep-min="$SLEEP_MIN" \
        --sleep-max="$SLEEP_MAX" >/dev/null 2>&1 &
  done

  # Watcher runs in the foreground, streaming heartbeat to terminal.
  # It exits when queue has been idle for $DEADLINE seconds AND queue>0 was
  # observed at least once.
  docker compose exec -T app php artisan repro:watch --deadline="$DEADLINE"
  local rc=$?

  # Kill any still-running hammers and reap them.
  kill $(jobs -p) 2>/dev/null
  wait 2>/dev/null

  local verdict
  case "$rc" in
    0)   verdict="PASSED" ;;
    1)   verdict="FAILED" ;;
    124) verdict="TIMEOUT" ;;
    *)   verdict="ERROR(rc=$rc)" ;;
  esac

  echo "============================================================"
  echo "framework${version} ${verdict}"
  echo "============================================================"
}

for version in "${FRAMEWORK_VERSIONS[@]}"; do
  test_version "$version"
done
