<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\IssueTracker;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Files tickets in GitHub Issues, Linear or Jira Cloud with the customer's own credentials. */
final class IssueTrackerClient
{
    /**
     * Create a ticket and return its key (such as #42, ENG-42 or OPS-42) and address. Throws with the tracker's own
     * message when it refuses.
     *
     * @param  IssueTracker  $tracker
     * @param  string  $title
     * @param  string  $body  plain text
     * @return array{key: string, url: string}
     */
    public function create(IssueTracker $tracker, string $title, string $body): array
    {
        $settings = $tracker->settings;

        return match ($tracker->kind) {
            'github' => $this->github($settings, $title, $body),
            'linear' => $this->linear($settings, $title, $body),
            'jira' => $this->jira($settings, $title, $body),
            default => throw new RuntimeException('Unknown tracker.'),
        };
    }

    /**
     * Open a GitHub issue in the repository.
     *
     * @param  array<string, string>  $settings
     * @param  string  $title
     * @param  string  $body
     * @return array{key: string, url: string}
     */
    private function github(array $settings, string $title, string $body): array
    {
        $response = Http::timeout(15)->withToken($settings['token'] ?? '')->accept('application/vnd.github+json')->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post('https://api.github.com/repos/'.($settings['repository'] ?? '').'/issues', ['title' => $title, 'body' => $body]);
        if ($response->failed() || ! is_int($response->json('number'))) {
            throw new RuntimeException('GitHub answered HTTP '.$response->status().': '.(string) $response->json('message', 'no details'));
        }

        return ['key' => '#'.$response->json('number'), 'url' => (string) $response->json('html_url')];
    }

    /**
     * Create a Linear issue in the team.
     *
     * @param  array<string, string>  $settings
     * @param  string  $title
     * @param  string  $body
     * @return array{key: string, url: string}
     */
    private function linear(array $settings, string $title, string $body): array
    {
        $response = Http::timeout(15)->withHeaders(['Authorization' => $settings['api_key'] ?? ''])->acceptJson()->post('https://api.linear.app/graphql', [
            'query' => 'mutation IssueCreate($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { identifier url } } }',
            'variables' => ['input' => ['teamId' => $settings['team_id'] ?? '', 'title' => $title, 'description' => $body]],
        ]);
        $issue = $response->json('data.issueCreate.issue');
        if ($response->failed() || ! is_array($issue) || ! is_string($issue['identifier'] ?? null)) {
            throw new RuntimeException('Linear answered HTTP '.$response->status().': '.(string) $response->json('errors.0.message', 'no details'));
        }

        return ['key' => $issue['identifier'], 'url' => (string) ($issue['url'] ?? '')];
    }

    /**
     * Create a Jira Cloud bug in the project.
     *
     * @param  array<string, string>  $settings
     * @param  string  $title
     * @param  string  $body
     * @return array{key: string, url: string}
     */
    private function jira(array $settings, string $title, string $body): array
    {
        $site = rtrim($settings['site'] ?? '', '/');
        $paragraphs = array_map(fn (string $text): array => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]], array_values(array_filter(explode("\n\n", $body))));
        $response = Http::timeout(15)->withBasicAuth($settings['email'] ?? '', $settings['token'] ?? '')->acceptJson()->post($site.'/rest/api/3/issue', ['fields' => [
            'project' => ['key' => $settings['project_key'] ?? ''],
            'summary' => mb_substr($title, 0, 250),
            'issuetype' => ['name' => 'Bug'],
            'description' => ['type' => 'doc', 'version' => 1, 'content' => $paragraphs],
        ]]);
        if ($response->failed() || ! is_string($response->json('key'))) {
            $errors = $response->json('errors');

            throw new RuntimeException('Jira answered HTTP '.$response->status().': '.(is_array($errors) && $errors !== [] ? implode(' ', array_map('strval', $errors)) : (string) $response->json('errorMessages.0', 'no details')));
        }

        return ['key' => (string) $response->json('key'), 'url' => $site.'/browse/'.$response->json('key')];
    }
}
