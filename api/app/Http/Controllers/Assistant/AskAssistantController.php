<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Actions\Assistant\AskAssistant;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AssistantConversation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AskAssistantController
{
    /**
     * Ask the assistant a question and show the conversation while it answers.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  AskAssistant  $ask
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, AskAssistant $ask): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:2000'], 'conversation' => ['nullable', 'string', 'size:26']]);
        $conversation = isset($data['conversation'])
            ? AssistantConversation::query()->where('account_id', $account->id)->where('user_id', $user->id)->findOrFail((string) $data['conversation'])
            : null;
        $conversation = $ask->handle($user, $account, (string) $data['question'], $conversation);

        return to_route('assistant', ['conversation' => $conversation->id]);
    }
}
