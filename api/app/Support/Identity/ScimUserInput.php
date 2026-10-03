<?php

declare(strict_types=1);

namespace App\Support\Identity;

use App\Exceptions\ScimException;

/**
 * Reads SCIM User resources and PATCH operations (as Okta and Microsoft Entra ID send them) into the few things we
 * keep: email, name, external ID and whether the person is active.
 */
final class ScimUserInput
{
    /**
     * Read a full User resource (POST or PUT).
     *
     * @param  array<string, mixed>  $resource
     * @return array{email?: string, name?: string, external_id?: string|null, active?: bool}
     */
    public static function fromResource(array $resource): array
    {
        $changes = [];
        $email = self::email($resource);
        if ($email !== null) {
            $changes['email'] = $email;
        }
        $name = self::name($resource);
        if ($name !== null) {
            $changes['name'] = $name;
        }
        if (array_key_exists('externalId', $resource)) {
            $changes['external_id'] = is_scalar($resource['externalId']) ? mb_substr((string) $resource['externalId'], 0, 255) : null;
        }
        if (array_key_exists('active', $resource)) {
            $changes['active'] = self::boolean($resource['active']);
        }

        return $changes;
    }

    /**
     * Read PATCH operations. Paths may be given (active, userName, externalId, displayName, name.*) or the value may
     * be an object of attributes; "remove" of externalId clears it.
     *
     * @param  array<string, mixed>  $patch
     * @return array{email?: string, name?: string, external_id?: string|null, active?: bool}
     *
     * @throws ScimException
     */
    public static function fromPatch(array $patch): array
    {
        $operations = $patch['Operations'] ?? null;
        if (! is_array($operations)) {
            throw new ScimException(400, 'Operations are missing.', 'invalidSyntax');
        }
        $changes = [];
        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw new ScimException(400, 'Each operation must be an object.', 'invalidSyntax');
            }
            $op = strtolower((string) ($operation['op'] ?? ''));
            $path = is_string($operation['path'] ?? null) ? $operation['path'] : null;
            $value = $operation['value'] ?? null;
            if (! in_array($op, ['add', 'replace', 'remove'], true)) {
                throw new ScimException(400, "Unsupported operation {$op}.", 'invalidSyntax');
            }
            if ($op === 'remove') {
                if ($path !== null && strcasecmp($path, 'externalId') === 0) {
                    $changes['external_id'] = null;
                }

                continue;
            }
            $resource = $path === null ? (is_array($value) ? $value : []) : self::nest($path, $value);
            $changes = [...$changes, ...self::fromResource($resource)];
        }

        return $changes;
    }

    /**
     * Turn a path and value into the resource fragment it sets.
     *
     * @param  string  $path
     * @param  mixed  $value
     * @return array<string, mixed>
     */
    private static function nest(string $path, mixed $value): array
    {
        $lower = strtolower($path);
        if (str_starts_with($lower, 'emails')) {
            return ['emails' => [['value' => $value, 'primary' => true]]];
        }
        if (str_starts_with($lower, 'name.')) {
            return ['name' => [substr($path, 5) => $value]];
        }

        return [match ($lower) {
            'active' => 'active', 'username' => 'userName', 'externalid' => 'externalId', 'displayname' => 'displayName', default => $path
        } => $value];
    }

    /**
     * Find the email: userName when it's an address, else the primary (or first) email.
     *
     * @param  array<string, mixed>  $resource
     * @return string|null
     */
    private static function email(array $resource): ?string
    {
        $candidates = [$resource['userName'] ?? null];
        $emails = is_array($resource['emails'] ?? null) ? array_values(array_filter($resource['emails'], is_array(...))) : [];
        usort($emails, fn (array $a, array $b): int => (int) (($b['primary'] ?? false) === true) <=> (int) (($a['primary'] ?? false) === true));
        foreach ($emails as $email) {
            $candidates[] = $email['value'] ?? null;
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL) !== false) {
                return mb_strtolower(trim($candidate));
            }
        }

        return null;
    }

    /**
     * Find a display name: displayName, name.formatted, or given and family names.
     *
     * @param  array<string, mixed>  $resource
     * @return string|null
     */
    private static function name(array $resource): ?string
    {
        $name = is_array($resource['name'] ?? null) ? $resource['name'] : [];
        $given = is_string($name['givenName'] ?? null) ? $name['givenName'] : '';
        $family = is_string($name['familyName'] ?? null) ? $name['familyName'] : '';
        foreach ([$resource['displayName'] ?? null, $name['formatted'] ?? null, trim($given.' '.$family)] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return mb_substr(trim($candidate), 0, 255);
            }
        }

        return null;
    }

    /**
     * Read a boolean the way identity providers send them (Entra ID sends "True" and "False").
     *
     * @param  mixed  $value
     * @return bool
     */
    private static function boolean(mixed $value): bool
    {
        return is_string($value) ? strtolower($value) === 'true' : (bool) $value;
    }
}
