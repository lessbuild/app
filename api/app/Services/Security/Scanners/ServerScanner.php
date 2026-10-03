<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use App\Contracts\Security\Scanner;
use App\Data\Security\Finding;
use App\Models\Project;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Infrastructure\ServerShell;
use App\Services\Security\ServerAudit;

/** Audits the servers a project runs on: SSH, the firewall and open ports, fail2ban, updates and the OS release. */
final class ServerScanner implements Scanner
{
    /**
     * Create a new ServerScanner instance.
     *
     * @param  ServerShell  $shell  Runs the audit on each server.
     * @param  ServerAudit  $audit  Gathers the facts and judges them.
     * @param  ProjectServersQuery  $servers  Finds the project's servers.
     */
    public function __construct(private readonly ServerShell $shell, private readonly ServerAudit $audit, private readonly ProjectServersQuery $servers) {}

    /**
     * Get the scanner's kind.
     *
     * @return string
     */
    public function kind(): string
    {
        return 'servers';
    }

    /**
     * Get the scanner's name.
     *
     * @return string
     */
    public function label(): string
    {
        return __('Server hardening and updates');
    }

    /**
     * Server hardening comes with Pro and above.
     *
     * @return string
     */
    public function flag(): string
    {
        return 'security.servers';
    }

    /**
     * Audit each of the project's servers. Servers that can't be reached are left out, keeping their earlier findings.
     *
     * @param  Project  $project
     * @return array<string, list<Finding>>
     */
    public function scan(Project $project): array
    {
        $results = [];
        foreach ($this->servers->handle($project) as $server) {
            $result = $this->shell->run($server, $this->audit->script());
            if ($result->successful()) {
                $results["server:{$server->id}"] = $this->audit->findings($server, $result->output);
            }
        }

        return $results;
    }
}
