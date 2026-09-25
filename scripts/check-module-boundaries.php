<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$modules = ['Deployer', 'Monitor', 'Analytics'];
$violations = [];

foreach ($modules as $sourceModule) {
    $sourceDirectory = $root.'/app/Modules/'.$sourceModule;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $tokens = token_get_all((string) file_get_contents($path));

        foreach ($tokens as $token) {
            if (! is_array($token)
                || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($token[1], '\\');
            if (! str_starts_with($name, 'App\\Modules\\')) {
                continue;
            }

            $segments = explode('\\', $name);
            $referencedModule = $segments[2] ?? null;

            if ($referencedModule !== null
                && $referencedModule !== $sourceModule
                && in_array($referencedModule, $modules, true)) {
                $relativePath = substr($path, strlen($root) + 1);
                $violations[] = "{$relativePath}: {$sourceModule} references {$referencedModule} directly";
            }
        }

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['DB', 'Schema'], true)) {
                continue;
            }

            $significant = [];
            for ($cursor = $index + 1; $cursor < count($tokens) && count($significant) < 5; $cursor++) {
                $candidate = $tokens[$cursor];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $significant[] = $candidate;
            }

            if (count($significant) < 5
                || tokenText($significant[0]) !== '::'
                || tokenText($significant[1]) !== 'connection'
                || tokenText($significant[2]) !== '(') {
                continue;
            }

            $connection = $significant[3];
            if (! is_array($connection) || $connection[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $connectionName = trim($connection[1], "'\"");
            $referencedModule = match ($connectionName) {
                'deployer' => 'Deployer',
                'monitor' => 'Monitor',
                'analytics' => 'Analytics',
                default => null,
            };

            if ($referencedModule !== null && $referencedModule !== $sourceModule) {
                $relativePath = substr($path, strlen($root) + 1);
                $violations[] = "{$relativePath}: {$sourceModule} accesses the {$referencedModule} database directly";
            }
        }
    }
}

if ($violations !== []) {
    fwrite(STDERR, "Product modules must integrate through Core contracts, not peer-module classes:\n");
    fwrite(STDERR, implode("\n", array_values(array_unique($violations)))."\n");

    exit(1);
}

fwrite(STDOUT, "Product module dependency boundaries are clear.\n");

/** @param array{0: int, 1: string, 2: int}|string $token */
function tokenText(array|string $token): string
{
    return is_array($token) ? $token[1] : $token;
}
