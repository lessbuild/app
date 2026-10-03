<?php

declare(strict_types=1);

namespace App\Services\Security\Scanners;

use App\Contracts\Security\Scanner;
use App\Data\Security\Finding;
use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;

/** Checks who can reach the account: whether access has been reviewed lately, members without two-factor sign-in, and API tokens nobody uses. */
final class AccessScanner implements Scanner
{
    /**
     * Get the scanner's kind.
     *
     * @return string
     */
    public function kind(): string
    {
        return 'access';
    }

    /**
     * Get the scanner's name.
     *
     * @return string
     */
    public function label(): string
    {
        return __('Access and reviews');
    }

    /**
     * Access reviews come with the Team plan.
     *
     * @return string
     */
    public function flag(): string
    {
        return 'security.access_reviews';
    }

    /**
     * Report an overdue access review, members without two-factor sign-in (unless the account requires it anyway), and
     * API tokens unused for 90 days.
     *
     * @param  Project  $project
     * @return array<string, list<Finding>>
     */
    public function scan(Project $project): array
    {
        $account = $project->account;
        $findings = [];
        $last = SecurityAccessReview::query()->where('project_id', $project->id)->latest('id')->first();
        if ($last === null || $last->created_at === null || $last->created_at->lt(now()->subDays(SecurityAccessReview::DUE_AFTER_DAYS))) {
            $findings[] = new Finding('review-due', 'medium', $last === null ? (string) __('Access hasn’t been reviewed yet') : (string) __('The access review is overdue'),
                $last?->created_at === null ? null : (string) __('The last review was :time.', ['time' => $last->created_at->diffForHumans()]), $account->name,
                fix: (string) __('Go through Security → Access and confirm who should keep access.'));
        }
        if (! $account->require_two_factor) {
            foreach (Membership::query()->where('account_id', $account->id)->with('user')->get() as $membership) {
                if ($membership->user->two_factor_confirmed_at === null) {
                    $findings[] = new Finding("no-2fa|{$membership->user_id}", 'medium', (string) __(':name signs in without two-factor authentication', ['name' => $membership->user->name]),
                        $membership->user->email, $membership->user->name,
                        fix: (string) __('Ask them to turn it on in Your settings → Security, or require it for everyone in Account → Security.'));
                }
            }
        }
        foreach (ApiToken::query()->where('account_id', $account->id)->get() as $token) {
            $idleSince = $token->last_used_at ?? $token->created_at;
            if ($idleSince !== null && $idleSince->lt(now()->subDays(90))) {
                $findings[] = new Finding("idle-token|{$token->id}", 'low', (string) __('The API token “:name” hasn’t been used in 90 days', ['name' => $token->name]),
                    $token->last_used_at === null ? (string) __('Never used.') : (string) __('Last used :time.', ['time' => $token->last_used_at->diffForHumans()]), $token->name,
                    fix: (string) __('Revoke it if nothing needs it.'));
            }
        }

        return ['account' => $findings];
    }
}
