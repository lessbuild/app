<?php

declare(strict_types=1);

namespace App\Actions\Assistant;

use App\Jobs\Assistant\AnswerAssistantQuestion;
use App\Models\Account;
use App\Models\AssistantConversation;
use App\Models\User;
use App\Services\Assistant\Claude;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AskAssistant
{
    /**
     * Create a new AskAssistant instance.
     *
     * @param  Claude  $claude  Says whether the assistant is set up.
     */
    public function __construct(private readonly Claude $claude) {}

    /**
     * Ask the assistant a question, starting a conversation or continuing one of the person's own, and queue the
     * answer. Each account has a daily number of questions.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  string  $question
     * @param  AssistantConversation|null  $conversation
     * @return AssistantConversation
     */
    public function handle(User $user, Account $account, string $question, ?AssistantConversation $conversation = null): AssistantConversation
    {
        if (! $this->claude->configured()) {
            throw ValidationException::withMessages(['question' => __('The assistant isn’t set up on this installation yet.')]);
        }
        if ($conversation !== null && ($conversation->user_id !== $user->id || $conversation->account_id !== $account->id)) {
            abort(404);
        }
        if ($conversation?->status === 'thinking') {
            throw ValidationException::withMessages(['question' => __('Wait for the answer to the last question first.')]);
        }
        $limit = max(1, (int) config('services.anthropic.questions_per_day'));
        $key = 'assistant:'.$account->id.':'.now()->format('Y-m-d');
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages(['question' => __('This account has asked :limit questions today. Ask again tomorrow.', ['limit' => $limit])]);
        }
        RateLimiter::hit($key, 86400);

        $question = trim($question);
        $conversation ??= (new AssistantConversation)->forceFill(['account_id' => $account->id, 'user_id' => $user->id, 'title' => Str::limit($question, 110), 'messages' => []]);
        $conversation->forceFill([
            'messages' => [...$conversation->messages, ['role' => 'user', 'content' => $question]],
            'status' => 'thinking', 'error' => null,
        ])->save();
        AnswerAssistantQuestion::dispatch($conversation->id);

        return $conversation;
    }
}
