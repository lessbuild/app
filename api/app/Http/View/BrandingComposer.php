<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Models\AnalyticsSite;
use App\Models\StatusPage;
use App\Services\Accounts\AccountBranding;
use Illuminate\View\View;

/** Gives client-facing pages (status pages, shared Analytics reports) the account's white-label branding, if any. */
final class BrandingComposer
{
    /**
     * Create a new BrandingComposer instance.
     *
     * @param  AccountBranding  $branding  Reads the account's branding.
     */
    public function __construct(private readonly AccountBranding $branding) {}

    /**
     * Share `$branding` (null for ours) with the page, from its status page or Analytics site's account.
     *
     * @param  View  $view
     * @return void
     */
    public function compose(View $view): void
    {
        $page = $view->getData()['page'] ?? null;
        $site = $view->getData()['site'] ?? null;
        $account = match (true) {
            $page instanceof StatusPage => $page->account,
            $site instanceof AnalyticsSite => $site->project->account,
            default => null,
        };
        $view->with('branding', $account === null ? null : $this->branding->for($account));
    }
}
