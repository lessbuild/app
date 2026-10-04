<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Enums\ServerType;
use App\Models\Server;

final readonly class ServerSummary
{
    /**
     * Create a new ServerSummary instance.
     *
     * A server as lists show it.
     *
     * @param  int  $id
     * @param  string  $name  Its display name, or its hostname.
     * @param  string  $type  The ServerType value.
     * @param  string  $typeLabel  Such as "App server".
     * @param  string|null  $ip  Its public IP address, once it has one.
     * @param  string|null  $provider  The provider's type, such as "Hetzner Cloud"; null for an imported server.
     * @param  string|null  $region
     * @param  string  $status  queued, waiting_for_ip, provisioning, active or failed.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $typeLabel,
        public ?string $ip,
        public ?string $provider,
        public ?string $region,
        public string $status,
    ) {}

    /**
     * Describe a server.
     *
     * @param  Server  $server
     * @return self
     */
    public static function from(Server $server): self
    {
        return new self(
            id: $server->id,
            name: $server->label(),
            type: $server->type->value,
            typeLabel: $server->type->label(),
            ip: $server->public_ip,
            provider: $server->provider?->type->label(),
            region: $server->region,
            status: $server->provisioning_status,
        );
    }

    /**
     * The kinds of server there are, with what each installs, for the forms that create or import one.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function types(): array
    {
        return array_map(fn (ServerType $type): array => ['value' => $type->value, 'label' => $type->label().' · '.implode(', ', $type->installs())], ServerType::cases());
    }
}
