# Independent monitoring

BuildPusher's built-in status page cannot report a total outage if it is hosted on the failed application. A release installation therefore needs an independent monitor and status host.

1. Configure external HTTPS checks for `https://buildpusher.com/api/health` and `https://buildpusher.com/status/report.json` at one-minute intervals.
2. Host the customer-facing status page outside the BuildPusher server and map `status.buildpusher.com` to it.
3. Configure the monitor's scheduler heartbeat URL as `EXTERNAL_MONITOR_HEARTBEAT_URL`.
4. Configure the independent public page as `EXTERNAL_STATUS_URL`.
5. Run `php artisan buildpusher:monitoring:heartbeat --verify-status` and confirm the external monitor records it. The command fails unless the private HTTPS heartbeat succeeds and the independent HTTPS status page is reachable.
6. Stop the scheduler in staging, confirm a missed-heartbeat incident, then restore it.

The heartbeat URL is configuration-only and is never returned by a public or authenticated HTTP endpoint.
