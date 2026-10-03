<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mcp;

use App\Models\Account;
use App\Models\User;
use App\Services\Assistant\AssistantTools;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * The Model Context Protocol endpoint (`POST /api/mcp`, streamable HTTP without streaming): people's own AI tools,
 * such as Claude, connect with an API token and get the assistant's read-only tools, limited to the token's scopes.
 */
final class HandleMcpRequestController
{
    /** The protocol revision this endpoint speaks. */
    private const string PROTOCOL = '2025-06-18';

    /**
     * Answer one JSON-RPC message: initialize, ping, tools/list or tools/call. Notifications get 202 with no body.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AssistantTools  $tools
     * @return JsonResponse|Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AssistantTools $tools): JsonResponse|Response
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account, 403);
        try {
            $message = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error(null, -32700, 'The request isn’t valid JSON.');
        }
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error(null, -32600, 'Send one JSON-RPC 2.0 request.');
        }
        $id = $message['id'] ?? null;
        if (! array_key_exists('id', $message)) {
            return response()->noContent(202);
        }
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];
        $allows = fn (string $scope): bool => $user->tokenCan($scope);

        return match ($message['method']) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => config('app.name').' assistant', 'version' => '1.0.0'],
                'instructions' => 'Read-only tools for '.$account->name.': deploys and their impact, errors, checks, incidents, Analytics and servers. Call list_projects first for IDs. Times are UTC.',
            ]),
            'ping' => $this->result($id, new stdClass),
            'tools/list' => $this->result($id, ['tools' => $tools->definitions('inputSchema', $allows)]),
            'tools/call' => $this->call($id, $params, $tools, $account, $user, $allows),
            default => $this->error($id, -32601, "There's no method {$message['method']}."),
        };
    }

    /**
     * Run a tool the token's scopes allow and return its result as JSON text, or the reason it couldn't run.
     *
     * @param  mixed  $id
     * @param  array<mixed>  $params
     * @param  AssistantTools  $tools
     * @param  Account  $account
     * @param  User  $user
     * @param  callable(string): bool  $allows
     * @return JsonResponse
     */
    private function call(mixed $id, array $params, AssistantTools $tools, Account $account, User $user, callable $allows): JsonResponse
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $scope = $tools->scope($name);
        if ($scope === null) {
            return $this->error($id, -32602, "There's no tool called {$name}.");
        }
        if (! $allows($scope)) {
            return $this->result($id, ['content' => [['type' => 'text', 'text' => "This token needs the {$scope} scope for {$name}."]], 'isError' => true]);
        }
        try {
            /** @var array<string, mixed> $arguments */
            $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            $result = $tools->call($name, $arguments, $account, $user);
        } catch (InvalidArgumentException $exception) {
            return $this->result($id, ['content' => [['type' => 'text', 'text' => $exception->getMessage()]], 'isError' => true]);
        }

        return $this->result($id, ['content' => [['type' => 'text', 'text' => (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]], 'structuredContent' => $result]);
    }

    /**
     * Build a JSON-RPC result.
     *
     * @param  mixed  $id
     * @param  mixed  $result
     * @return JsonResponse
     */
    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * Build a JSON-RPC error.
     *
     * @param  mixed  $id
     * @param  int  $code
     * @param  string  $message
     * @return JsonResponse
     */
    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }
}
