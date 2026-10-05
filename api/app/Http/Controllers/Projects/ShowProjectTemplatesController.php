<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Models\Server;
use App\Services\Projects\ProjectTemplates;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/templates`. */
final class ShowProjectTemplatesController
{
    /**
     * The built-in templates' icons, from the Acme theme's set.
     *
     * @var array<string, string>
     */
    private const ICONS = ['laravel' => 'code', 'nextjs' => 'layers', 'wordpress' => 'globe', 'static' => 'fileText'];

    /**
     * Return the project templates (built in, and the account's own), with the active app servers and Git providers a
     * template's website and repository can use.
     *
     * @param  Account  $account
     * @param  ProjectTemplates  $registry
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProjectTemplates $registry): JsonResponse
    {
        $templates = [];
        foreach ($registry->all($account->id) as $key => $template) {
            $saved = $template['saved'] ?? null;
            $templates[] = [
                'key' => (string) $key,
                'name' => $saved !== null ? (string) $template['name'] : __((string) $template['name']),
                'description' => $saved !== null ? (string) ($template['description'] ?? '') : __((string) ($template['description'] ?? '')),
                'savedId' => $saved,
                'environments' => count((array) ($template['environments'] ?? [])),
                'monitors' => count((array) ($template['monitors'] ?? [])),
                'goals' => count((array) ($template['goals'] ?? [])),
                'services' => array_values(array_filter((array) ($template['services'] ?? []), 'is_string')),
                'icon' => $saved !== null ? 'star' : (self::ICONS[(string) $key] ?? 'layers'),
            ];
        }

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'templates' => $templates,
            'servers' => Server::query()->where('account_id', $account->id)->where('type', ServerType::App)->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get()
                ->map(fn (Server $server): array => ['id' => $server->id, 'label' => $server->label()])->values(),
            'gitProviders' => Provider::query()->where('account_id', $account->id)->whereIn('type', [ProviderType::GitHub, ProviderType::GitLab, ProviderType::Bitbucket])->orderBy('name')->get()
                ->map(fn (Provider $provider): array => ['id' => $provider->id, 'label' => $provider->name.' ('.$provider->type->label().')'])->values(),
        ]);
    }
}
