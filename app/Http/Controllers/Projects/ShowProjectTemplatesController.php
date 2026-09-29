<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\ProviderType;
use App\Enums\ServerType;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowProjectTemplatesController
{
    /**
     * Show the templates, and the form for the chosen one: the account's active app servers and Git providers.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request): View
    {
        $templates = (array) config('templates');
        $chosen = is_string($request->query('template')) && isset($templates[$request->query('template')]) ? $request->query('template') : null;

        return view('projects.templates', [
            'account' => $account,
            'templates' => $templates,
            'chosen' => $chosen,
            'servers' => Server::query()->where('account_id', $account->id)->where('type', ServerType::App)->where('provisioning_status', Server::STATUS_ACTIVE)->orderBy('name')->get(),
            'gitProviders' => Provider::query()->where('account_id', $account->id)->whereIn('type', [ProviderType::GitHub, ProviderType::GitLab, ProviderType::Bitbucket])->orderBy('name')->get(),
        ]);
    }
}
