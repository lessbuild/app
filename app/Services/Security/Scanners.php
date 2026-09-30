<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\Scanner;
use App\Models\Account;
use App\Services\Billing\Entitlements;
use App\Services\Security\Scanners\DependencyScanner;
use App\Services\Security\Scanners\DomainScanner;
use Illuminate\Contracts\Container\Container;

/** The Security checks the platform runs, and which of them an account's plan includes. */
final class Scanners
{
    /**
     * The scanner classes, in the order the overview lists them.
     *
     * @var list<class-string<Scanner>>
     */
    public const CLASSES = [
        DependencyScanner::class,
        DomainScanner::class,
    ];

    /**
     * Create a new Scanners instance.
     *
     * @param  Container  $container  Builds the scanners.
     * @param  Entitlements  $entitlements  Reads the account's plan.
     */
    public function __construct(private readonly Container $container, private readonly Entitlements $entitlements) {}

    /**
     * Get every scanner, keyed by kind.
     *
     * @return array<string, Scanner>
     */
    public function all(): array
    {
        $scanners = [];
        foreach (self::CLASSES as $class) {
            $scanner = $this->container->make($class);
            $scanners[$scanner->kind()] = $scanner;
        }

        return $scanners;
    }

    /**
     * Get a scanner by kind.
     *
     * @param  string  $kind
     * @return Scanner|null
     */
    public function find(string $kind): ?Scanner
    {
        return $this->all()[$kind] ?? null;
    }

    /**
     * Determine whether an account's plan includes a scanner.
     *
     * @param  Account  $account
     * @param  Scanner  $scanner
     * @return bool
     */
    public function included(Account $account, Scanner $scanner): bool
    {
        return $scanner->flag() === null || $this->entitlements->for($account)->has($scanner->flag());
    }

    /**
     * Get how many hours apart the account's plan scans, weekly when it isn't set.
     *
     * @param  Account  $account
     * @return int
     */
    public function intervalHours(Account $account): int
    {
        return max(1, (int) ($this->entitlements->for($account)->limit('security.scan.hours') ?? 168));
    }
}
