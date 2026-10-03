<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\IsServerTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A port opened in the server's firewall (ufw), for everyone or one address or network.
 *
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property string $port one port or a range such as 8000:8010
 * @property string $protocol tcp or udp
 * @property string|null $source an IP address or CIDR network; null for anywhere
 * @property string $status pending, active, removing or failed
 * @property string|null $error
 * @property CarbonImmutable|null $applied_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Server $server
 */
class ServerFirewallRule extends Model
{
    use IsServerTask;

    /**
     * Describe who may connect.
     *
     * @return string
     */
    public function from(): string
    {
        return $this->source ?? __('Anywhere');
    }
}
