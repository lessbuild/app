<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\Server;
use RuntimeException;

/** The Caddy site for a load balancer (`/etc/caddy/websites/ha-{id}.conf`) and the scripts that write or remove it. */
final class LoadBalancerConfiguration
{
    /** Least-connections proxying to enabled, active nodes (repeated by weight), with active health checks; a 503 page without any. */
    public function site(LoadBalancer $balancer): string
    {
        $hostname = strtolower($balancer->hostname);
        if (preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $hostname) !== 1) {
            throw new RuntimeException('The hostname isn’t safe to write into Caddy.');
        }
        if (preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@%/-]*\z#D', $balancer->health_path) !== 1) {
            throw new RuntimeException('The health path isn’t safe to write into Caddy.');
        }
        $upstreams = [];
        foreach ($balancer->nodes as $node) {
            if ($node->is_enabled && $node->server->provisioning_status === Server::STATUS_ACTIVE && $node->server->public_ip !== null) {
                $address = str_contains($node->server->public_ip, ':') ? "[{$node->server->public_ip}]" : $node->server->public_ip;
                array_push($upstreams, ...array_fill(0, max(1, min(10, $node->weight)), "http://{$address}:{$node->upstream_port}"));
            }
        }
        if ($upstreams === []) {
            return "{$hostname} {\n    respond \"No healthy application servers\" 503\n}\n";
        }

        return "{$hostname} {\n    reverse_proxy ".implode(' ', $upstreams)." {\n        lb_policy least_conn\n        health_uri {$balancer->health_path}\n"
            ."        health_interval 10s\n        health_timeout 3s\n        fail_duration 30s\n        max_fails 2\n    }\n}\n";
    }

    /**
     * The script that writes the load balancer's site, formats it, validates the whole configuration and reloads Caddy.
     */
    public function apply(LoadBalancer $balancer): string
    {
        $file = escapeshellarg($this->path($balancer->id));

        return "set -e\nprintf '%s' ".escapeshellarg(base64_encode($this->site($balancer)))." | base64 --decode > {$file}\ncaddy fmt --overwrite {$file}\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy";
    }

    /**
     * The script that removes the site, validates and reloads Caddy.
     */
    public function remove(int $balancerId): string
    {
        return "set -e\nrm -f -- ".escapeshellarg($this->path($balancerId))."\ncaddy validate --config /etc/caddy/Caddyfile\nsystemctl reload caddy";
    }

    /**
     * Where the load balancer's site is written.
     */
    private function path(int $balancerId): string
    {
        return "/etc/caddy/websites/ha-{$balancerId}.conf";
    }
}
