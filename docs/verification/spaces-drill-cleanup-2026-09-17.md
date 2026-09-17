# Disposable Spaces repository cleanup — 2026-09-17

## Scope and correction

The September 16 authorized backup/restore drill removed its snapshots and
raw data, but a separate listing returned HTTP 403. Earlier handoff wording
attributed that response to the key's permissions without sufficient evidence.
The response alone did not establish the cause.

On September 17, a read-only, prefix-scoped ListObjectsV2 request signed by
libcurl returned HTTP 200 with the existing destination credentials. No key,
bucket policy, billing setting or application code was changed. The original
403's exact cause remains undetermined; permission denial must not be presented
as an established diagnosis.

## Exact targets and safety

- Isolated dev runtime: `buildpusher-main-runtime`, `APP_ENV=local`, its own
  `database/dev.sqlite`, destination `2`.
- Endpoint: `https://lon1.digitaloceanspaces.com`.
- Bucket: `builder-backup`.
- Exact disposable prefix: `buildpusher/websites/10/`.
- The disposable website `10` no longer existed and no jobs were pending.
- `ResticRepository` defines the website-specific prefix. The three remaining
  objects had timestamps within the recorded September 16 drill window.
- A non-truncated listing contained only the repository config (155 bytes),
  one index (194 bytes) and one key object (476 bytes): 825 bytes total.
  There were no snapshot, data or lock objects.
- Before deletion, a second listing had to match all three exact keys, sizes
  and timestamps; any difference would stop the script before deletion.
- Only those three explicit object keys were deleted. All three returned
  HTTP 204. No bucket-wide listing or deletion was used.
- A fresh non-truncated ListObjectsV2 request returned HTTP 200 with zero
  current objects under the exact prefix at approximately 04:51 UTC.
- The configured destination and every other prefix were preserved. No server
  was created; credentials were loaded internally and never printed or copied.

No recovery copy of the discarded empty-repository metadata was retained.
Historical object versions/delete markers and multipart uploads were neither
inspected nor purged; this record establishes absence of current objects only.

## Verification and next task

This is an evidence/cleanup slice, not an application change. The current
strict PHP result remains 1,547 tests / 12,962 assertions with full Pint
passing. Documentation diff checks pass; dependency lockfiles are unchanged.

The sanitized local diagnostic is retained at
`/mnt/volume_nyc1_1789401255960/codex-storage/Documents/Codex/buildpusher-spaces-check-BJAgjV/list-drill.php`.
It uses [libcurl's SigV4 support](https://curl.se/libcurl/c/CURLOPT_AWS_SIGV4.html)
for the [prefix-scoped listing](https://docs.aws.amazon.com/AmazonS3/latest/API/API_ListObjectsV2.html).
No credential file was created.

Next: continue preview-stack acceptance with explicit PostgreSQL/Valkey
readiness and cleanup evidence. The successful generic deployment/recovery
drill does not establish those separate workflows or production acceptance.
