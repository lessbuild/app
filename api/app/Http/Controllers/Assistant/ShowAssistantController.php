<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\Assistant\Claude;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ShowAssistantController
{
    /**
     * Show the assistant: the conversation asked for with ?conversation= (the person's own, in this account), the
     * recent ones, whether the assistant is set up, and the MCP address for using it from other AI tools. Answers come
     * as Markdown and as HTML with raw HTML and unsafe links stripped.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  Claude  $claude
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, Claude $claude): JsonResponse
    {
        $mine = AssistantConversation::query()->where('account_id', $account->id)->where('user_id', $user->id);
        $conversation = $request->filled('conversation') ? (clone $mine)->findOrFail((string) $request->query('conversation')) : null;

        return response()->json([
            'accountName' => $account->name,
            'conversation' => $conversation === null ? null : [
                'id' => $conversation->id,
                'status' => $conversation->status,
                'error' => $conversation->error,
                'lines' => array_map(fn (array $line): array => [
                    'role' => $line['role'],
                    'text' => $line['text'],
                    'html' => $line['role'] === 'user' ? null : (string) Str::markdown($line['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                ], $conversation->transcript()),
            ],
            'conversations' => (clone $mine)->latest('updated_at')->limit(15)->get(['id', 'title', 'updated_at'])
                ->map(fn (AssistantConversation $recent): array => ['id' => $recent->id, 'title' => $recent->title])->values(),
            'configured' => $claude->configured(),
            'mcpUrl' => route('api.mcp'),
        ]);
    }
}
