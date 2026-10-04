<?php

declare(strict_types=1);

namespace App\Data\Infrastructure;

use App\Enums\ProviderType;
use App\Models\Provider;

final readonly class ProviderSummary
{
    /**
     * Create a new ProviderSummary instance.
     *
     * A provider as lists show it (never its token).
     *
     * @param  int  $id
     * @param  string  $name
     * @param  string  $type  The ProviderType value.
     * @param  string  $typeLabel  Such as "Hetzner Cloud".
     * @param  string  $purpose  What the type is for, such as "Servers".
     * @param  bool  $hostsServers  Whether servers can be created with it.
     * @param  int  $serverCount  Its servers, when counted.
     * @param  string  $status  healthy, failed or unchecked.
     * @param  string|null  $checkedAt  ISO 8601, when the connection was last checked.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $typeLabel,
        public string $purpose,
        public bool $hostsServers,
        public int $serverCount,
        public string $status,
        public ?string $checkedAt,
    ) {}

    /**
     * Describe a provider.
     *
     * @param  Provider  $provider
     * @return self
     */
    public static function from(Provider $provider): self
    {
        return new self(
            id: $provider->id,
            name: $provider->name,
            type: $provider->type->value,
            typeLabel: $provider->type->label(),
            purpose: $provider->type->purpose(),
            hostsServers: $provider->type->hostsServers(),
            serverCount: (int) ($provider->servers_count ?? 0),
            status: $provider->connection_status ?? 'unchecked',
            checkedAt: $provider->connection_checked_at?->toIso8601String(),
        );
    }

    /**
     * List the types a provider can be, with what each is for.
     *
     * @return list<array{value: string, label: string, purpose: string}>
     */
    public static function types(): array
    {
        return array_map(fn (ProviderType $type): array => ['value' => $type->value, 'label' => $type->label(), 'purpose' => $type->purpose()], ProviderType::cases());
    }
}
