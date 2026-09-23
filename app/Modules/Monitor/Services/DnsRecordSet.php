<?php

namespace App\Modules\Monitor\Services;

final class DnsRecordSet
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS'];

    public const EXPECTED_LIMIT = 16384;

    public const RESPONSE_LIMIT = 32768;

    public function hostname(string $hostname): ?string
    {
        $hostname = $this->domain($hostname);
        if ($hostname === null) {
            return null;
        }
        foreach (['localhost', 'local', 'internal', 'lan', 'home', 'onion', 'invalid'] as $suffix) {
            if (str_ends_with($hostname, '.'.$suffix)) {
                return null;
            }
        }

        return $hostname;
    }

    private function domain(string $hostname): ?string
    {
        $hostname = strtolower(str_ends_with($hostname, '.') ? substr($hostname, 0, -1) : $hostname);

        return strlen($hostname) <= 253 && preg_match('/^(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{2,59})$/D', $hostname)
            ? $hostname : null;
    }

    /** @return list<string>|null */
    public function expected(string $type, string $text): ?array
    {
        if (strlen($text) > self::EXPECTED_LIMIT || ! in_array($type, self::TYPES, true)) {
            return null;
        }
        $lines = array_values(array_filter(preg_split('/\\r\\n|\\r|\\n/', $text), static fn (string $line): bool => $line !== ''));
        if ($lines === [] || count($lines) > 20) {
            return null;
        }
        $values = [];
        foreach ($lines as $line) {
            $value = $this->value($type, $line);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        return $this->sorted($values);
    }

    /** @param list<array<string, mixed>> $records
     * @return list<string>|null
     */
    public function observed(string $type, array $records): ?array
    {
        if (! in_array($type, self::TYPES, true) || count($records) > 100) {
            return null;
        }
        $encoded = json_encode($records);
        if ($encoded === false || strlen($encoded) > self::RESPONSE_LIMIT) {
            return null;
        }
        $values = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! is_string($record['type'] ?? null)) {
                return null;
            }
            if ($record['type'] !== $type) {
                continue;
            }
            $raw = match ($type) {
                'A' => $record['ip'] ?? null,
                'AAAA' => $record['ipv6'] ?? null,
                'CNAME', 'NS' => $record['target'] ?? null,
                'MX' => isset($record['pri'], $record['target']) && is_int($record['pri']) && is_string($record['target'])
                    ? $record['pri'].' '.($record['target'] === '' ? '.' : $record['target']) : null,
                'TXT' => $this->txt($record),
            };
            $value = is_string($raw) ? $this->value($type, $raw) : null;
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        return $this->sorted($values);
    }

    private function value(string $type, string $value): ?string
    {
        if ($type === 'TXT') {
            return strlen($value) <= 4096 && mb_check_encoding($value, 'UTF-8') && ! preg_match('/[\\x00-\\x1F\\x7F]/', $value) ? $value : null;
        }
        $value = trim($value);
        if ($type === 'A' || $type === 'AAAA') {
            return filter_var($value, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false
                ? inet_ntop(inet_pton($value)) : null;
        }
        if ($type === 'MX') {
            if (! preg_match('/^([0-9]{1,5})[ \\t]+(.+)$/D', $value, $parts) || (int) $parts[1] > 65535) {
                return null;
            }
            if ($parts[2] === '.') {
                return (int) $parts[1] === 0 ? '0 .' : null;
            }
            $target = $this->domain($parts[2]);

            return $target === null ? null : (int) $parts[1].' '.$target;
        }

        return $this->domain($value);
    }

    /** @param array<string, mixed> $record */
    private function txt(array $record): ?string
    {
        if (isset($record['entries'])) {
            if (! is_array($record['entries']) || count(array_filter($record['entries'], 'is_string')) !== count($record['entries'])) {
                return null;
            }

            return implode('', $record['entries']);
        }

        return is_string($record['txt'] ?? null) ? $record['txt'] : null;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
