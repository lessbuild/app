<?php

namespace App\Modules\Deployer\Services;

use InvalidArgumentException;

class ServerDiagnosticOutputParser
{
    /**
     * Parse only the scalar fields emitted by the fixed diagnostic script.
     *
     * @return array{version: int, uid: int, architecture: string, php_version: string, storage_path: string, storage_writable: string, disk_percent: int, load_1m: float, memory_percent: int, process_count: int}
     *
     * @throws InvalidArgumentException If output exceeds the bound or contains an invalid response.
     */
    public function parse(string $output): array
    {
        $maximum = max(1, (int) config('lessbuild.server_diagnostic_output_max_characters', 16384));
        if (strlen($output) > $maximum) {
            throw new InvalidArgumentException('The server diagnostic response was too large.');
        }

        $allowed = [
            'bp_diag_version',
            'uid',
            'architecture',
            'php_version',
            'storage_path',
            'storage_writable',
            'disk_percent',
            'load_1m',
            'memory_percent',
            'process_count',
        ];
        $values = [];

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match('/\A([a-z0-9_]+)=([^\r\n]{1,128})\z/D', $line, $matches)
                || ! in_array($matches[1], $allowed, true)
                || array_key_exists($matches[1], $values)) {
                throw new InvalidArgumentException('The server diagnostic response was invalid.');
            }

            $values[$matches[1]] = $matches[2];
        }

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $values)) {
                throw new InvalidArgumentException('The server diagnostic response was incomplete.');
            }
        }

        if ($values['bp_diag_version'] !== '1'
            || ! preg_match('/\A\d{1,6}\z/D', $values['uid'])
            || ! preg_match('/\A[A-Za-z0-9_.-]{1,32}\z/D', $values['architecture'])
            || ! preg_match('/\A(?:missing|\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?)\z/D', $values['php_version'])
            || ! in_array($values['storage_path'], ['present', 'missing'], true)
            || ! in_array($values['storage_writable'], ['yes', 'no'], true)
            || ! preg_match('/\A\d{1,3}\z/D', $values['disk_percent'])
            || ! preg_match('/\A\d{1,6}(?:\.\d{1,2})?\z/D', $values['load_1m'])
            || ! preg_match('/\A\d{1,3}\z/D', $values['memory_percent'])
            || ! preg_match('/\A\d{1,9}\z/D', $values['process_count'])) {
            throw new InvalidArgumentException('The server diagnostic response contained an invalid value.');
        }

        $diskPercent = (int) $values['disk_percent'];
        $memoryPercent = (int) $values['memory_percent'];
        if ($diskPercent > 100 || $memoryPercent > 100) {
            throw new InvalidArgumentException('The server diagnostic response contained an invalid percentage.');
        }

        return [
            'version' => 1,
            'uid' => (int) $values['uid'],
            'architecture' => $values['architecture'],
            'php_version' => $values['php_version'],
            'storage_path' => $values['storage_path'],
            'storage_writable' => $values['storage_writable'],
            'disk_percent' => $diskPercent,
            'load_1m' => (float) $values['load_1m'],
            'memory_percent' => $memoryPercent,
            'process_count' => (int) $values['process_count'],
        ];
    }
}
