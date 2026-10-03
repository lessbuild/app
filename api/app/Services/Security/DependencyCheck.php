<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\VulnerabilityDatabase;
use App\Data\Security\Finding;
use App\Support\Security\LockFiles;

/** Turns lock files into findings for their known vulnerabilities. Shared by the dependency scan and the deploy gate. */
final class DependencyCheck
{
    /**
     * Create a new DependencyCheck instance.
     *
     * @param  VulnerabilityDatabase  $database  Knows which versions are vulnerable.
     */
    public function __construct(private readonly VulnerabilityDatabase $database) {}

    /**
     * Find the vulnerabilities in a release's lock files. Development-only packages count one severity lower, since
     * they don't run in production.
     *
     * @param  string|null  $composerLock
     * @param  string|null  $npmLock
     * @param  string|null  $subject  what the files belong to, e.g. the website's name
     * @return list<Finding>
     */
    public function findings(?string $composerLock, ?string $npmLock, ?string $subject = null): array
    {
        $packages = [...($composerLock === null ? [] : LockFiles::composer($composerLock)), ...($npmLock === null ? [] : LockFiles::npm($npmLock))];
        if ($packages === []) {
            return [];
        }
        $lookup = $this->database->lookup(array_map(fn (array $package): array => ['ecosystem' => $package['ecosystem'], 'name' => $package['name'], 'version' => $package['version']], $packages));
        $lower = ['critical' => 'high', 'high' => 'medium', 'medium' => 'low', 'low' => 'low'];
        $findings = [];
        foreach ($packages as $index => $package) {
            foreach ($lookup[$index] ?? [] as $vulnerability) {
                $severity = $package['dev'] ? $lower[$vulnerability['severity']] ?? 'low' : $vulnerability['severity'];
                $findings[] = new Finding(
                    key: $package['ecosystem'].':'.$package['name'].':'.$vulnerability['id'],
                    severity: $severity,
                    title: (string) __(':package :version: :summary', ['package' => $package['name'], 'version' => $package['version'], 'summary' => $vulnerability['summary']]),
                    detail: $package['dev']
                        ? (string) __(':id in the :ecosystem package :package :version, used in development only.', ['id' => $vulnerability['id'], 'ecosystem' => $package['ecosystem'] === 'npm' ? 'npm' : 'Composer', 'package' => $package['name'], 'version' => $package['version']])
                        : (string) __(':id in the :ecosystem package :package :version.', ['id' => $vulnerability['id'], 'ecosystem' => $package['ecosystem'] === 'npm' ? 'npm' : 'Composer', 'package' => $package['name'], 'version' => $package['version']]),
                    subject: $subject,
                    url: $vulnerability['url'],
                    fix: $vulnerability['fixed'] === null ? (string) __('No fixed version yet. Consider replacing :package or limiting how it’s used.', ['package' => $package['name']])
                        : (string) __('Update :package to :fixed or later.', ['package' => $package['name'], 'fixed' => $vulnerability['fixed']]),
                    data: ['package' => $package['name'], 'version' => $package['version'], 'vulnerability' => $vulnerability['id'], 'fixed' => $vulnerability['fixed'], 'dev' => $package['dev']],
                );
            }
        }

        return $findings;
    }
}
