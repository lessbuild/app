# BuildPusher for Laravel

Sends a Laravel app's work to BuildPusher Monitoring, linked by trace, and marks each release:

- requests, with a timeline of their queries, mail and notifications, and query, cache and mail counts;
- exceptions, with the (hashed) signed-in user, so issues show how many people were affected;
- slow queries, and N+1 queries (the same query five or more times in one request);
- queue jobs, scheduled tasks and artisan commands;
- warning-level logs.

```bash
composer require buildpusher/laravel
```

Add to `.env` (the key is on Monitoring → Setup):

```dotenv
BUILDPUSHER_TOKEN=your-ingest-key
BUILDPUSHER_RELEASE=v1.4.0   # optional: tie errors to releases
```

That's it: events are batched and sent after each response or job. Mark a deploy from your pipeline with:

```bash
php artisan buildpusher:deploy v1.4.0 --commit="$GIT_SHA"
```

Options (publish with `php artisan vendor:publish --tag=buildpusher-config`): `BUILDPUSHER_REQUEST_SAMPLE_RATE` (0–1), `BUILDPUSHER_SLOW_QUERY_MS` (default 100), `BUILDPUSHER_LOG_LEVEL` (default warning), `BUILDPUSHER_SERVICE`, and `ignore_paths`.

Nothing is sent without a token, and sending never throws: if BuildPusher can't be reached, the batch is dropped.
