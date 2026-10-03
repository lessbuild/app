<?php

declare(strict_types=1);

namespace App\Queries\Infrastructure;

use App\Models\Website;
use App\Services\Infrastructure\ServerShell;
use Illuminate\Validation\ValidationException;

/**
 * Reads a website's folder on its server over SSH: a folder's entries, the end of a text file, or matches in its logs.
 * Paths are relative to /var/www/{slug}; the server resolves them (following links) and refuses anything outside.
 */
final class WebsiteFilesQuery
{
    /**
     * How many lines of a file are shown.
     *
     * @var int
     */
    public const TAIL_LINES = 500;

    /**
     * Create a new WebsiteFilesQuery instance.
     *
     * @param  ServerShell  $shell  Runs the reads on the server.
     */
    public function __construct(private readonly ServerShell $shell) {}

    /**
     * List a folder's entries (folders first), at most 500.
     *
     * @param  Website  $website
     * @param  string  $path  relative to the website's folder; empty for the folder itself
     * @return array{entries: list<array{name: string, type: string, size: int, modified: int}>, error: string|null}
     */
    public function folder(Website $website, string $path): array
    {
        $result = $this->run($website, $path, 'test -d "$P" || { echo "Not a folder." >&2; exit 4; }'."\n"
            ."find \"\$P\" -mindepth 1 -maxdepth 1 -printf '%y\\t%s\\t%T@\\t%f\\n' 2>/dev/null | head -n 500");
        if ($result['error'] !== null) {
            return ['entries' => [], 'error' => $result['error']];
        }
        $entries = [];
        foreach (preg_split('/\R/', trim($result['output'])) ?: [] as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) === 4) {
                $entries[] = ['name' => $parts[3], 'type' => match ($parts[0]) {
                    'd' => 'folder', 'l' => 'link', default => 'file'
                }, 'size' => (int) $parts[1], 'modified' => (int) $parts[2]];
            }
        }
        usort($entries, fn (array $a, array $b): int => [$a['type'] !== 'folder', strtolower($a['name'])] <=> [$b['type'] !== 'folder', strtolower($b['name'])]);

        return ['entries' => $entries, 'error' => null];
    }

    /**
     * Get the last lines of a text file (at most 1 MB of them).
     *
     * @param  Website  $website
     * @param  string  $path
     * @return array{content: string, error: string|null}
     */
    public function tail(Website $website, string $path): array
    {
        $result = $this->run($website, $path, 'test -f "$P" || { echo "Not a file." >&2; exit 4; }'."\n"
            .'if [ -s "$P" ] && ! head -c 8192 "$P" | grep -Iq .; then echo "This looks like a binary file." >&2; exit 5; fi'."\n"
            .'tail -n '.self::TAIL_LINES.' -- "$P" | tail -c 1048576');

        return ['content' => $result['output'], 'error' => $result['error']];
    }

    /**
     * Search the website's Laravel logs (storage/logs) for a phrase, newest matches last, at most 200 lines.
     *
     * @param  Website  $website
     * @param  string  $phrase
     * @return array{matches: list<array{file: string, line: int, text: string}>, error: string|null}
     *
     * @throws ValidationException
     */
    public function search(Website $website, string $phrase): array
    {
        $phrase = trim($phrase);
        if ($phrase === '' || mb_strlen($phrase) > 200 || preg_match('/[\r\n\x00]/', $phrase) === 1) {
            throw ValidationException::withMessages(['q' => __('Search for one line of text, up to 200 characters.')]);
        }
        $result = $this->run($website, 'shared/storage/logs', 'test -d "$P" || exit 0'."\n"
            .'grep -rIHn --max-count=200 -F -e '.escapeshellarg($phrase).' -- "$P" 2>/dev/null | tail -n 200 | cut -c1-2000 || true');
        $matches = [];
        foreach (preg_split('/\R/', trim($result['output'])) ?: [] as $line) {
            if (preg_match('#^(.*?):(\d+):(.*)$#s', $line, $match) === 1) {
                $matches[] = ['file' => basename($match[1]), 'line' => (int) $match[2], 'text' => $match[3]];
            }
        }

        return ['matches' => $matches, 'error' => $result['error']];
    }

    /**
     * Run a read on the server with $P set to the resolved path, after checking it stays inside the website's folder.
     *
     * @param  Website  $website
     * @param  string  $path
     * @param  string  $command
     * @return array{output: string, error: string|null}
     *
     * @throws ValidationException
     */
    private function run(Website $website, string $path, string $command): array
    {
        $path = trim($path, '/');
        if (preg_match('#^[A-Za-z0-9._@+ -]*(/[A-Za-z0-9._@+ -]+)*$#', $path) !== 1 || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw ValidationException::withMessages(['path' => __('That isn’t a path inside the website’s folder.')]);
        }
        $server = $website->server;
        if ($server === null) {
            return ['output' => '', 'error' => (string) __('The website has no server.')];
        }
        $root = '/var/www/'.$website->deployment_slug;
        $script = implode("\n", [
            'ROOT='.escapeshellarg($root),
            'P=$(realpath -e -- '.escapeshellarg($root.($path !== '' ? '/'.$path : '')).' 2>/dev/null) || { echo "It doesn’t exist." >&2; exit 3; }',
            'case "$P" in "$ROOT"|"$ROOT"/*) ;; *) echo "That leads outside the website’s folder." >&2; exit 3 ;; esac',
            $command,
        ]);
        $result = $this->shell->run($server, $script);

        return $result->successful()
            ? ['output' => $result->output, 'error' => null]
            : ['output' => '', 'error' => trim($result->errorOutput) !== '' ? trim($result->errorOutput) : (string) __('The server couldn’t read it.')];
    }
}
