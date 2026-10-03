<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SetPrivateNetworkTrust
{
    /**
     * The name every rule this adds starts with, so turning trust off removes exactly those.
     *
     * @var string
     */
    public const RULE_PREFIX = 'Private network: ';

    /**
     * Create a new SetPrivateNetworkTrust instance.
     *
     * @param  SaveServerTask  $save  Adds firewall rules.
     * @param  RemoveServerTask  $remove  Removes them.
     */
    public function __construct(private readonly SaveServerTask $save, private readonly RemoveServerTask $remove) {}

    /**
     * Let the account's other servers at the same provider and region reach this one over the private network (a
     * firewall rule per server's private IP, every TCP port), or stop. Rules for servers added later are added when
     * this is turned on again.
     *
     * @param  User  $actor
     * @param  Server  $server
     * @param  bool  $trust
     * @return int how many servers are trusted now
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Server $server, bool $trust): int
    {
        Gate::forUser($actor)->authorize('runCommands', $server);
        if ($trust && $server->private_ip === null) {
            throw ValidationException::withMessages(['trust' => __('This server has no private IP. Servers in the same provider region get one automatically at most providers.')]);
        }
        $existing = ServerFirewallRule::query()->where('server_id', $server->id)->where('name', 'like', self::RULE_PREFIX.'%')->where('status', '!=', 'removing')->get();
        if (! $trust) {
            $existing->each(fn (ServerFirewallRule $rule) => $this->remove->handle($actor, $rule));
            $server->forceFill(['trust_private_network' => false])->save();

            return 0;
        }
        $peers = Server::query()->where('account_id', $server->account_id)->where('provider_id', $server->provider_id)->where('region', $server->region)
            ->whereKeyNot($server->id)->whereNotNull('private_ip')->get();
        foreach ($peers as $peer) {
            if (! $existing->contains('source', $peer->private_ip)) {
                $this->save->handle($actor, $server, ServerFirewallRule::class, [
                    'name' => mb_substr(self::RULE_PREFIX.$peer->label(), 0, 60), 'port' => '1:65535', 'protocol' => 'tcp', 'source' => $peer->private_ip,
                ]);
            }
        }
        $server->forceFill(['trust_private_network' => true])->save();

        return $peers->count();
    }
}
