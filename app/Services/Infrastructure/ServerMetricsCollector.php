<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use App\Models\ServerMetric;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Reads load, CPU, memory, disk, network and process counts from /proc over SSH and records them; keeps 30 days. */
final class ServerMetricsCollector
{
    private const SCRIPT = <<<'BASH'
    set -e
    awk '{printf "load_1m=%s\nload_5m=%s\nload_15m=%s\n", $1, $2, $3}' /proc/loadavg
    awk '/MemTotal:/ {total=$2} /MemAvailable:/ {available=$2} END {if (total > 0) printf "memory_percent=%.0f\n", ((total-available)/total)*100}' /proc/meminfo
    df -P /var/www | awk 'NR==2 {gsub(/%/, "", $5); print "disk_percent=" $5}'
    awk '{printf "uptime_seconds=%.0f\n", $1}' /proc/uptime
    read -r _ u1 n1 s1 i1 w1 q1 x1 t1 _ < /proc/stat
    total1=$((u1+n1+s1+i1+w1+q1+x1+t1)); idle1=$((i1+w1))
    sleep 1
    read -r _ u2 n2 s2 i2 w2 q2 x2 t2 _ < /proc/stat
    total2=$((u2+n2+s2+i2+w2+q2+x2+t2)); idle2=$((i2+w2))
    delta_total=$((total2-total1)); delta_idle=$((idle2-idle1))
    if [ "$delta_total" -gt 0 ]; then echo "cpu_percent=$(((delta_total-delta_idle)*100/delta_total))"; else echo 'cpu_percent=0'; fi
    awk -F'[: ]+' 'NR > 2 && $2 != "lo" {rx += $3; tx += $11} END {printf "network_rx_bytes=%.0f\nnetwork_tx_bytes=%.0f\n", rx, tx}' /proc/net/dev
    awk '$3 !~ /^(loop|ram)/ {read += $6; written += $10} END {printf "disk_read_bytes=%.0f\ndisk_write_bytes=%.0f\n", read*512, written*512}' /proc/diskstats
    printf 'process_count=%s\n' "$(ps -e --no-headers | wc -l)"
    BASH;

    public function __construct(private readonly ServerShell $shell) {}

    public function collect(Server $server): ServerMetric
    {
        $result = $this->shell->run($server, self::SCRIPT);
        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput) !== '' ? trim($result->errorOutput) : 'Unable to collect server metrics.');
        }
        $values = [];
        foreach (preg_split('/\R/', trim($result->output)) ?: [] as $line) {
            if (preg_match('/\A([a-z0-9_]+)=([0-9]+(?:\.[0-9]+)?)\z/D', $line, $match) === 1) {
                $values[$match[1]] = $match[2];
            }
        }
        foreach (['load_1m', 'load_5m', 'load_15m', 'memory_percent', 'disk_percent', 'uptime_seconds'] as $key) {
            if (! array_key_exists($key, $values)) {
                throw new RuntimeException("The server didn’t report {$key}.");
            }
        }
        $percent = fn (string $key): int => max(0, min(100, (int) ($values[$key] ?? 0)));
        $count = fn (string $key): int => max(0, (int) ($values[$key] ?? 0));
        $metric = $server->metrics()->create([
            'load_1m' => min(999999.99, (float) $values['load_1m']), 'load_5m' => min(999999.99, (float) $values['load_5m']), 'load_15m' => min(999999.99, (float) $values['load_15m']),
            'cpu_percent' => $percent('cpu_percent'), 'memory_percent' => $percent('memory_percent'), 'disk_percent' => $percent('disk_percent'),
            'network_rx_bytes' => $count('network_rx_bytes'), 'network_tx_bytes' => $count('network_tx_bytes'),
            'disk_read_bytes' => $count('disk_read_bytes'), 'disk_write_bytes' => $count('disk_write_bytes'),
            'process_count' => $count('process_count'), 'uptime_seconds' => $count('uptime_seconds'), 'recorded_at' => CarbonImmutable::now('UTC'),
        ]);
        $server->metrics()->where('recorded_at', '<', CarbonImmutable::now('UTC')->subDays(30))->delete();

        return $metric;
    }
}
