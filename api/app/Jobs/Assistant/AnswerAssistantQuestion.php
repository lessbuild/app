<?php

declare(strict_types=1);

namespace App\Jobs\Assistant;

use App\Models\AssistantConversation;
use App\Services\Assistant\AssistantTools;
use App\Services\Assistant\Claude;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Answers the latest question in a conversation: Claude calls the assistant's tools until it can answer. */
final class AnswerAssistantQuestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** How many rounds of tool calls an answer may take. */
    private const int MAX_ROUNDS = 8;

    /**
     * One attempt; the person can ask again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Room for several model calls and tools.
     *
     * @var int
     */
    public int $timeout = 600;

    /**
     * Create a new AnswerAssistantQuestion instance.
     *
     * @param  string  $conversationId  The conversation with a question waiting.
     */
    public function __construct(public readonly string $conversationId) {}

    /**
     * Ask Claude, run the tools it calls for the person who asked, and store the exchange until it answers.
     *
     * @param  Claude  $claude
     * @param  AssistantTools  $tools
     * @return void
     */
    public function handle(Claude $claude, AssistantTools $tools): void
    {
        $conversation = AssistantConversation::query()->with(['account', 'user'])->find($this->conversationId);
        if ($conversation === null || $conversation->status !== 'thinking') {
            return;
        }
        app()->setLocale($conversation->user->locale ?? (string) config('app.locale'));
        $messages = $conversation->messages;
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $reply = $claude->messages($this->system($conversation), $messages, $tools->definitions());
            $messages[] = ['role' => 'assistant', 'content' => $reply['content']];
            if ($reply['stop_reason'] !== 'tool_use') {
                $conversation->forceFill(['messages' => $messages, 'status' => 'idle', 'error' => null])->save();

                return;
            }
            $results = [];
            foreach ($reply['content'] as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }
                try {
                    $output = (string) json_encode($tools->call((string) ($block['name'] ?? ''), is_array($block['input'] ?? null) ? $block['input'] : [], $conversation->account, $conversation->user), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => mb_substr($output, 0, 60000)];
                } catch (InvalidArgumentException $exception) {
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'] ?? '', 'content' => $exception->getMessage(), 'is_error' => true];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new RuntimeException(__('The assistant needed too many steps for this question. Try asking something narrower.'));
    }

    /**
     * Record why the answer failed, keeping the question.
     *
     * @param  Throwable  $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        // Its own failures explain themselves; anything else (a timeout, say) gets a general message.
        $message = $exception instanceof RuntimeException ? $exception->getMessage() : __('The assistant couldn’t answer just now. Try again.');
        AssistantConversation::query()->whereKey($this->conversationId)->first()?->forceFill(['status' => 'failed', 'error' => mb_substr($message, 0, 500)])->save();
    }

    /**
     * Build the system prompt: who's asking, in which account, and how to answer.
     *
     * @param  AssistantConversation  $conversation
     * @return string
     */
    private function system(AssistantConversation $conversation): string
    {
        $language = config('app.supported_locales.'.app()->getLocale(), 'English');
        $app = (string) config('app.name');

        return <<<PROMPT
        You are the assistant inside {$app}, a platform that deploys apps to the customer's own cloud servers, monitors them (errors, traces, uptime checks, incidents) and runs privacy-friendly analytics. You're talking to {$conversation->user->name} in the account "{$conversation->account->name}". The time now is {$this->now()} UTC.

        Answer questions about their deploys, errors, checks, incidents, Analytics and servers using the tools, which only show what this person may see. Call list_projects first when you need IDs. For "why did X change" questions, look at deploys around the time and compare before and after with deploy_impact, then name the likely cause and how confident you are. Quote the numbers you rely on. If the data doesn't answer the question, say so and suggest where in {$app} to look. Never invent data. Keep answers short, use Markdown sparingly, and reply in {$language}.
        PROMPT;
    }

    /**
     * Get the current time for the prompt.
     *
     * @return string
     */
    private function now(): string
    {
        return now()->utc()->format('Y-m-d H:i');
    }
}
