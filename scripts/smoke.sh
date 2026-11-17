#!/usr/bin/env bash
# Post-deploy smoke test: the site is up, the login page renders, and the
# webhook endpoint rejects an unsigned request.
#
# Usage: scripts/smoke.sh https://pixelcat.laravel.cloud
#
# Waits up to 10 minutes for the deploy to finish before checking.

set -euo pipefail

base_url="${1:?Usage: scripts/smoke.sh <base-url>}"
base_url="${base_url%/}"

status() {
    curl --silent --output /dev/null --write-out '%{http_code}' --max-time 15 "$@"
}

echo "Waiting for ${base_url}/up ..."
for attempt in $(seq 1 60); do
    if [ "$(status "${base_url}/up")" = "200" ]; then
        break
    fi
    if [ "$attempt" = "60" ]; then
        echo "FAIL: ${base_url}/up did not return 200 within 10 minutes"
        exit 1
    fi
    sleep 10
done

# Give a just-finished deploy a moment to switch traffic.
sleep 30

failures=0

check() {
    local name="$1" expected="$2" actual="$3"
    if [ "$actual" = "$expected" ]; then
        echo "ok   ${name} (${actual})"
    else
        echo "FAIL ${name}: expected ${expected}, got ${actual}"
        failures=$((failures + 1))
    fi
}

check "health check" 200 "$(status "${base_url}/up")"
check "login page" 200 "$(status "${base_url}/login")"
check "unsigned webhook is rejected" 400 "$(status -X POST \
    -H 'Content-Type: application/json' \
    -H 'Pixelforge-Signature: t=1,v1=invalid' \
    --data '{"id":"evt_smoke","type":"ping"}' \
    "${base_url}/webhooks/pixelforge")"

if [ "$failures" -gt 0 ]; then
    echo "${failures} smoke check(s) failed"
    exit 1
fi

echo "Smoke test passed"
