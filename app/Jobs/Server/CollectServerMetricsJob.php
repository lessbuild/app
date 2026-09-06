<?php

namespace App\Jobs\Server;

use App\Models\Server;
use App\Services\MetricAlertEvaluator;
use App\Services\Runner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CollectServerMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 240;

    public function __construct(public int $serverId) {}

    public function uniqueId(): string
    {
        return (string) $this->serverId;
    }

    public function handle(Runner $runner, ?MetricAlertEvaluator $alerts = null): void
    {
        $server = Server::query()->whereKey($this->serverId)->where('provisioning_status', Server::STATUS_ACTIVE)->first();
        if (! $server) {
            return;
        }
        $command = <<<'BASH'
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
        $result = $runner->server($server)->create()->execute($command);
        if (! $result->isSuccessful()) {
            throw new RuntimeException(trim($result->getErrorOutput()) ?: 'Unable to collect server metrics.');
        }
        $values = [];
        foreach (preg_split('/\R/', trim($result->getOutput())) ?: [] as $line) {
            if (preg_match('/\A([a-z0-9_]+)=([0-9]+(?:\.[0-9]+)?)\z/D', $line, $matches)) {
                $values[$matches[1]] = $matches[2];
            }
        }
        foreach (['load_1m', 'load_5m', 'load_15m', 'memory_percent', 'disk_percent', 'uptime_seconds'] as $key) {
            if (! array_key_exists($key, $values)) {
                throw new RuntimeException("Server metric {$key} was missing from the response.");
            }
        }
        $metric = $server->metrics()->create([
            'load_1m' => min(999999.99, (float) $values['load_1m']),
            'load_5m' => min(999999.99, (float) $values['load_5m']),
            'load_15m' => min(999999.99, (float) $values['load_15m']),
            'cpu_percent' => max(0, min(100, (int) ($values['cpu_percent'] ?? 0))),
            'memory_percent' => max(0, min(100, (int) $values['memory_percent'])),
            'disk_percent' => max(0, min(100, (int) $values['disk_percent'])),
            'network_rx_bytes' => max(0, (int) ($values['network_rx_bytes'] ?? 0)),
            'network_tx_bytes' => max(0, (int) ($values['network_tx_bytes'] ?? 0)),
            'disk_read_bytes' => max(0, (int) ($values['disk_read_bytes'] ?? 0)),
            'disk_write_bytes' => max(0, (int) ($values['disk_write_bytes'] ?? 0)),
            'process_count' => max(0, (int) ($values['process_count'] ?? 0)),
            'uptime_seconds' => max(0, (int) $values['uptime_seconds']),
            'recorded_at' => now(),
        ]);
        ($alerts ?? app(MetricAlertEvaluator::class))->evaluate($metric);
        $server->metrics()->where('recorded_at', '<', now()->subDays(30))->delete();
    }
}
