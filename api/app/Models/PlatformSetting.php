<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One setting the platform keeps about itself, as an encrypted JSON value.
 *
 * @property string $key
 * @property array<string, mixed> $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(key: 'key', keyType: 'string', incrementing: false, dateFormat: 'Y-m-d H:i:s.u')]
class PlatformSetting extends Model
{
    /**
     * Read a setting, or null when it isn't set.
     *
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    public static function read(string $key): ?array
    {
        return self::query()->find($key)?->value;
    }

    /**
     * Save a setting.
     *
     * @param  string  $key
     * @param  array<string, mixed>  $value
     * @return void
     */
    public static function write(string $key, array $value): void
    {
        $setting = self::query()->find($key) ?? new self;
        $setting->forceFill(['key' => $key, 'value' => $value])->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * Encrypts the value as JSON.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'encrypted:array'];
    }
}
