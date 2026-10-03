<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\ProjectTemplate;

/** The templates an account can start a project from: the built-in ones (config/templates.php) and its saved ones. */
final class ProjectTemplates
{
    /**
     * Get every template for the account, keyed; saved ones are marked with `saved` => their ID.
     *
     * @param  string  $accountId
     * @return array<string, array<string, mixed>>
     */
    public function all(string $accountId): array
    {
        $templates = (array) config('templates');
        foreach (ProjectTemplate::query()->where('account_id', $accountId)->orderBy('name')->get() as $template) {
            $templates[$template->key()] = [...$template->definition, 'name' => $template->name, 'description' => $template->description, 'saved' => $template->id];
        }

        return $templates;
    }

    /**
     * Find one template for the account.
     *
     * @param  string  $accountId
     * @param  string  $key
     * @return array<string, mixed>|null
     */
    public function find(string $accountId, string $key): ?array
    {
        return $this->all($accountId)[$key] ?? null;
    }
}
