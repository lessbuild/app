<?php

declare(strict_types=1);

namespace App\Data\Deploy;

use App\Models\Environment;
use App\Models\Provider;
use App\Models\Website;

final readonly class RepositoryFormOptions
{
    /**
     * Create a new RepositoryFormOptions instance.
     *
     * The choices on a repository's form.
     *
     * @param  list<array{value: string, label: string}>  $providers  Git providers, such as "Acme (GitHub App)".
     * @param  list<array{value: string, label: string}>  $websites  Websites a repository can deploy to.
     * @param  list<array{value: string, label: string}>  $environments  The project's environments.
     */
    public function __construct(
        public array $providers,
        public array $websites,
        public array $environments,
    ) {}

    /**
     * Describe the choices RepositoryFormQuery found.
     *
     * @param  array{providers: iterable<Provider>, websites: iterable<Website>, environments: iterable<Environment>}  $form
     * @return self
     */
    public static function from(array $form): self
    {
        $providers = $websites = $environments = [];
        foreach ($form['providers'] as $provider) {
            $providers[] = ['value' => (string) $provider->id, 'label' => $provider->name.' ('.($provider->isGitHubApp() ? __('GitHub App') : $provider->type->label()).')'];
        }
        foreach ($form['websites'] as $website) {
            $websites[] = ['value' => (string) $website->id, 'label' => $website->name.' ('.$website->url.')'];
        }
        foreach ($form['environments'] as $environment) {
            $environments[] = ['value' => $environment->id, 'label' => $environment->name];
        }

        return new self($providers, $websites, $environments);
    }
}
