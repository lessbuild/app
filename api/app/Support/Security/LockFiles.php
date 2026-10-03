<?php

declare(strict_types=1);

namespace App\Support\Security;

/** Reads the exact package versions out of Composer and npm lock files. */
final class LockFiles
{
    /**
     * List the packages in a composer.lock, marking development-only ones.
     *
     * @param  string  $json
     * @return list<array{ecosystem: string, name: string, version: string, dev: bool}>
     */
    public static function composer(string $json): array
    {
        $lock = json_decode($json, true);
        if (! is_array($lock)) {
            return [];
        }
        $packages = [];
        foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
            foreach ((array) ($lock[$section] ?? []) as $package) {
                $name = is_array($package) ? ($package['name'] ?? null) : null;
                $version = is_array($package) ? ($package['version'] ?? null) : null;
                if (is_string($name) && is_string($version) && preg_match('/^v?\d/', $version) === 1) {
                    $packages[] = ['ecosystem' => 'Packagist', 'name' => $name, 'version' => ltrim($version, 'v'), 'dev' => $dev];
                }
            }
        }

        return $packages;
    }

    /**
     * List the packages in a package-lock.json (lockfile versions 1 to 3), marking development-only ones.
     *
     * @param  string  $json
     * @return list<array{ecosystem: string, name: string, version: string, dev: bool}>
     */
    public static function npm(string $json): array
    {
        $lock = json_decode($json, true);
        if (! is_array($lock)) {
            return [];
        }
        $packages = [];
        if (is_array($lock['packages'] ?? null)) {
            foreach ($lock['packages'] as $path => $package) {
                if (! is_string($path) || ! str_contains($path, 'node_modules/') || ! is_array($package) || ! is_string($package['version'] ?? null)) {
                    continue;
                }
                $packages[] = ['ecosystem' => 'npm', 'name' => substr($path, strrpos($path, 'node_modules/') + 13), 'version' => $package['version'], 'dev' => (bool) ($package['dev'] ?? false)];
            }
        } elseif (is_array($lock['dependencies'] ?? null)) {
            $walk = function (array $dependencies) use (&$walk, &$packages): void {
                foreach ($dependencies as $name => $package) {
                    if (is_string($name) && is_array($package) && is_string($package['version'] ?? null)) {
                        $packages[] = ['ecosystem' => 'npm', 'name' => $name, 'version' => $package['version'], 'dev' => (bool) ($package['dev'] ?? false)];
                        if (is_array($package['dependencies'] ?? null)) {
                            $walk($package['dependencies']);
                        }
                    }
                }
            };
            $walk($lock['dependencies']);
        }
        $unique = [];
        foreach ($packages as $package) {
            $unique[$package['name'].'@'.$package['version']] ??= $package;
        }

        return array_values($unique);
    }
}
