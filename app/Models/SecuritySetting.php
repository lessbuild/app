<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A project's Security settings: whether attackers are blocked automatically, for how long, and the addresses never
 * blocked (the team's office, a monitoring service).
 *
 * @property int $id
 * @property string $project_id
 * @property bool $autoblock
 * @property int $block_hours
 * @property list<string>|null $allowlist addresses and networks (CIDR) never blocked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SecuritySetting extends Model
{
    /**
     * The attributes that can't be mass assigned: all of them; settings are written with forceFill.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Get the attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['autoblock' => 'boolean', 'block_hours' => 'integer', 'allowlist' => 'array'];
    }

    /**
     * Get a project's settings, or the defaults (blocking on, for 24 hours) when it has none yet.
     *
     * @param  string  $projectId
     * @return self
     */
    public static function forProject(string $projectId): self
    {
        return self::query()->where('project_id', $projectId)->first()
            ?? (new self)->forceFill(['project_id' => $projectId, 'autoblock' => true, 'block_hours' => 24, 'allowlist' => []]);
    }

    /**
     * Determine whether an address is on the allowlist (exactly, or inside one of its IPv4 networks).
     *
     * @param  string  $ip
     * @return bool
     */
    public function allows(string $ip): bool
    {
        foreach ($this->allowlist ?? [] as $entry) {
            if ($entry === $ip) {
                return true;
            }
            if (str_contains($entry, '/') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                [$network, $bits] = explode('/', $entry, 2);
                $mask = -1 << (32 - max(0, min(32, (int) $bits)));
                if (($long = ip2long($network)) !== false && (ip2long($ip) & $mask) === ($long & $mask)) {
                    return true;
                }
            }
        }

        return false;
    }
}
