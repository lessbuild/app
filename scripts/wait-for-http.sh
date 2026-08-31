#!/usr/bin/env bash

set -Eeuo pipefail

URL="${1:?Pass the HTTP URL to probe.}"
ATTEMPTS="${2:-15}"
DELAY_SECONDS="${3:-1}"
CURL_BIN="${CURL_BIN:-$(command -v curl)}"

if [[ ! "${ATTEMPTS}" =~ ^[1-9][0-9]*$ ]]; then
    echo "Readiness attempts must be a positive integer." >&2
    exit 2
fi

if [[ ! "${DELAY_SECONDS}" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    echo "Readiness delay must be a non-negative number." >&2
    exit 2
fi

for ((attempt = 1; attempt <= ATTEMPTS; attempt++)); do
    if "${CURL_BIN}" \
        --fail \
        --silent \
        --connect-timeout 1 \
        --max-time 2 \
        --output /dev/null \
        "${URL}"; then
        exit 0
    fi

    if ((attempt < ATTEMPTS)); then
        sleep "${DELAY_SECONDS}"
    fi
done

echo "HTTP readiness check failed after ${ATTEMPTS} attempt(s): ${URL}" >&2
exit 1
