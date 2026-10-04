<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Actions\Assistant\AskAssistant;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\AssistantConversation;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AskAssistantController
{
    /**
     * Ask the assistant a question, starting a conversation or following up on one of the person's own, and send the
     * app to the conversation while it's answered.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  AskAssistant  $ask
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, AskAssistant $ask): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:2000'], 'conversation' => ['nullable', 'string', 'size:26']]);
        $conversation = isset($data['conversation'])
            ? AssistantConversation::query()->where('account_id', $account->id)->where('user_id', $user->id)->findOrFail((string) $data['conversation'])
            : null;
        $conversation = $ask->handle($user, $account, (string) $data['question'], $conversation);

        return response()->json(['redirect' => route('assistant', ['conversation' => $conversation->id], false)]);
    }
}
