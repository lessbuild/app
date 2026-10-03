<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\SecuritySetting;
use App\Models\Server;
use App\Models\Website;
use App\Services\Billing\Entitlements;
use App\Services\Infrastructure\ServerShell;
use App\Support\IpRanges;

/**
 * Watches a project's web servers for attacks and blocks the attackers. Every few minutes it summarises each
 * website's access log for the last ten minutes per address, on the server itself, and blocks addresses that tried
 * too many failed sign-ins, probed for known vulnerable paths, or flooded the site. Blocks go into the server's
 * firewall and lift on their own when they expire.
 */
final class AttackWatch
{
    /**
     * Failed sign-ins from one address in the window before it's blocked.
     *
     * @var int
     */
    public const FAILED_LOGINS = 20;

    /**
     * Requests for known vulnerable paths (such as /.env or /wp-login.php) before blocking.
     *
     * @var int
     */
    public const PROBES = 10;

    /**
     * Requests from one address in ten minutes that count as a flood.
     *
     * @var int
     */
    public const FLOOD = 3000;

    /**
     * Cloudflare's published address ranges. A site behind Cloudflare's proxy can log Cloudflare's own address as the
     * client, and blocking it would take the site down, so these are never blocked.
     *
     * @var list<string>
     */
    private const CLOUDFLARE = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22', '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];

    /**
     * Create a new AttackWatch instance.
     *
     * @param  ServerShell  $shell  Reads the logs and changes the firewall.
     * @param  Entitlements  $entitlements  Checks the plan includes automatic blocking.
     */
    public function __construct(private readonly ServerShell $shell, private readonly Entitlements $entitlements) {}

    /**
     * Check every project with Security on, automatic blocking in its plan and turned on, and lift expired blocks.
     * Returns how many addresses were blocked.
     *
     * @return int
     */
    public function run(): int
    {
        $this->liftExpired();
        $blocked = 0;
        Project::query()->whereHas('enabledServices', fn ($query) => $query->where('service', 'security'))->with('account')->orderBy('id')
            ->each(function (Project $project) use (&$blocked): void {
                if ($this->entitlements->for($project->account)->has('security.autoblock') && SecuritySetting::forProject($project->id)->autoblock) {
                    $blocked += $this->watch($project);
                }
            });

        return $blocked;
    }

    /**
     * Check one project's servers and block its attackers.
     *
     * @param  Project  $project
     * @return int
     */
    public function watch(Project $project): int
    {
        $settings = SecuritySetting::forProject($project->id);
        $websites = Website::query()->whereIn('environment_id', $project->environments()->select('id'))->whereNotNull('server_id')
            ->where('provisioning_status', Website::STATUS_ACTIVE)->with('server')->get()->groupBy('server_id');
        $blocked = 0;
        foreach ($websites as $serverWebsites) {
            $server = $serverWebsites->first()?->server;
            if ($server === null || $server->provisioning_status !== Server::STATUS_ACTIVE) {
                continue;
            }
            $logs = $serverWebsites->map(fn (Website $website): string => "/var/log/caddy/{$website->deployment_slug}.access.log")->values()->all();
            $result = $this->shell->run($server, $this->summaryScript($logs));
            if (! $result->successful()) {
                continue;
            }
            foreach ($this->attackers($result->output) as $ip => [$reason, $hits, $detail]) {
                if ($settings->allows($ip) || SecurityBlock::query()->where('server_id', $server->id)->where('ip', $ip)->whereNull('lifted_at')->where('expires_at', '>', now())->exists()) {
                    continue;
                }
                $block = $this->shell->run($server, 'ufw insert 1 deny from '.escapeshellarg($ip).' comment '.escapeshellarg('buildpusher security block'));
                if (! $block->successful()) {
                    continue;
                }
                (new SecurityBlock)->forceFill([
                    'project_id' => $project->id, 'server_id' => $server->id, 'ip' => $ip, 'reason' => $reason, 'hits' => $hits, 'detail' => $detail,
                    'expires_at' => now()->addHours(max(1, $settings->block_hours)),
                ])->save();
                $blocked++;
            }
        }

        return $blocked;
    }

    /**
     * Remove expired blocks from their servers' firewalls.
     *
     * @return void
     */
    public function liftExpired(): void
    {
        SecurityBlock::query()->whereNull('lifted_at')->where('expires_at', '<=', now())->with('server')->each(function (SecurityBlock $block): void {
            $this->lift($block);
        });
    }

    /**
     * Remove one block from its server's firewall and mark it lifted.
     *
     * @param  SecurityBlock  $block
     * @param  string|null  $userId  who lifted it early, if anyone
     * @return void
     */
    public function lift(SecurityBlock $block, ?string $userId = null): void
    {
        if ($block->server->provisioning_status === Server::STATUS_ACTIVE) {
            $this->shell->run($block->server, 'ufw delete deny from '.escapeshellarg($block->ip).' || true');
        }
        $block->forceFill(['lifted_at' => now(), 'lifted_by' => $userId])->save();
    }

    /**
     * Get the script that summarises the last ten minutes of the given JSON access logs per address, one line each:
     * ip, total requests, failed sign-ins, probes of vulnerable paths and not-found responses.
     *
     * @param  array<int, string>  $logs
     * @return string
     */
    public function summaryScript(array $logs): string
    {
        $files = implode(' ', array_map('escapeshellarg', $logs));
        $python = <<<'PY'
        import json, re, sys, time
        since = time.time() - 600
        login = re.compile(r'(login|signin|sign-in|wp-login\.php|xmlrpc\.php|/admin|/auth)', re.I)
        probe = re.compile(r'(/\.env|/\.git/|/wp-admin|/wp-content|/wp-includes|/wp-login\.php|/xmlrpc\.php|/phpmyadmin|/pma/|/vendor/phpunit|/eval-stdin\.php|/cgi-bin/|/(php)?info\.php|/shell\.php|/actuator|/solr/|/boaform|/HNAP1|/\.aws/|/server-status|/\.DS_Store)', re.I)
        stats = {}
        for path in sys.argv[1:]:
            try:
                handle = open(path, 'rb')
            except OSError:
                continue
            with handle:
                handle.seek(0, 2)
                handle.seek(max(0, handle.tell() - 50_000_000))
                for line in handle:
                    try:
                        entry = json.loads(line)
                    except ValueError:
                        continue
                    if entry.get('ts', 0) < since:
                        continue
                    request = entry.get('request') or {}
                    ip = request.get('client_ip') or request.get('remote_ip')
                    if not ip:
                        continue
                    uri = request.get('uri', '')
                    status = int(entry.get('status', 0))
                    row = stats.setdefault(ip, [0, 0, 0, 0])
                    row[0] += 1
                    if request.get('method') == 'POST' and status in (401, 403, 419, 422, 429) and login.search(uri):
                        row[1] += 1
                    if probe.search(uri):
                        row[2] += 1
                    if status == 404:
                        row[3] += 1
        for ip, row in stats.items():
            if row[0] >= 50 or row[1] or row[2]:
                print(ip, *row)
        PY;

        return 'command -v python3 >/dev/null 2>&1 || exit 0; python3 -c '.escapeshellarg($python).' '.$files;
    }

    /**
     * Pick the addresses to block from the summary: failed sign-ins over the limit, probing, or a flood. Private and
     * reserved addresses (load balancers, the server itself) and Cloudflare's are never blocked.
     *
     * @param  string  $summary
     * @return array<string, array{0: string, 1: int, 2: string}> ip => [reason, hits, detail]
     */
    public function attackers(string $summary): array
    {
        $attackers = [];
        foreach (preg_split('/\R/', trim($summary)) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) !== 5 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || IpRanges::contains(self::CLOUDFLARE, $parts[0])) {
                continue;
            }
            [$ip, $total, $logins, $probes, $notFound] = [$parts[0], (int) $parts[1], (int) $parts[2], (int) $parts[3], (int) $parts[4]];
            $decision = match (true) {
                $logins >= self::FAILED_LOGINS => ['brute-force', $logins, (string) __(':count failed sign-ins in ten minutes', ['count' => $logins])],
                $probes >= self::PROBES => ['scanner', $probes, (string) __(':count requests for vulnerable paths and :missing not found in ten minutes', ['count' => $probes, 'missing' => $notFound])],
                $total >= self::FLOOD => ['flood', $total, (string) __(':count requests in ten minutes', ['count' => $total])],
                default => null,
            };
            if ($decision !== null) {
                $attackers[$ip] = $decision;
            }
        }

        return $attackers;
    }
}
