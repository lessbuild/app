<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\Assistant\Claude;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowAssistantController
{
    /**
     * Show the assistant: a conversation (the one asked for, or a new one), the person's recent conversations, and
     * how to connect their own AI tools.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  Claude  $claude
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, Claude $claude): View
    {
        $mine = AssistantConversation::query()->where('account_id', $account->id)->where('user_id', $user->id);
        $conversation = $request->filled('conversation') ? (clone $mine)->findOrFail((string) $request->query('conversation')) : null;

        return view('assistant.show', [
            'account' => $account,
            'conversation' => $conversation,
            'conversations' => (clone $mine)->latest('updated_at')->limit(15)->get(['id', 'title', 'updated_at']),
            'configured' => $claude->configured(),
            'mcpUrl' => route('api.mcp'),
        ]);
    }
}
