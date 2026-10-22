<?php

declare(strict_types=1);

namespace App\Data\Deploy;

use Closure;

final class RepositorySnapshot
{
    /**
     * Files already read, by path (null when the file isn't there).
     *
     * @var array<string, string|null>
     */
    private array $read = [];

    /**
     * Create a new RepositorySnapshot instance.
     *
     * A repository's files at one revision, as its Git provider lists them, read on demand.
     *
     * @param  list<string>  $paths  every file and folder path the provider listed, without a leading slash
     * @param  Closure(string): (string|null)  $reader  reads one file, or returns null when it isn't there
     */
    public function __construct(public readonly array $paths, private readonly Closure $reader) {}

    /**
     * Whether a file or folder exists.
     *
     * @param  string  $path
     * @return bool
     */
    public function has(string $path): bool
    {
        return in_array(trim($path, '/'), $this->paths, true);
    }

    /**
     * Whether anything exists under a folder.
     *
     * @param  string  $folder
     * @return bool
     */
    public function hasUnder(string $folder): bool
    {
        $prefix = trim($folder, '/').'/';

        return array_any($this->paths, fn (string $path): bool => str_starts_with($path, $prefix));
    }

    /**
     * Read a file, at most once; null when it isn't listed or can't be read.
     *
     * @param  string  $path
     * @return string|null
     */
    public function read(string $path): ?string
    {
        $path = trim($path, '/');
        if (! $this->has($path)) {
            return null;
        }

        if (! array_key_exists($path, $this->read)) {
            $this->read[$path] = ($this->reader)($path);
        }

        return $this->read[$path];
    }

    /**
     * Read a JSON file as an array; null when it's missing or not JSON.
     *
     * @param  string  $path
     * @return array<string, mixed>|null
     */
    public function json(string $path): ?array
    {
        $decoded = json_decode((string) $this->read($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
