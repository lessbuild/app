<?php

namespace App\Core\Services\Blueprints;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class NormalizeProjectBlueprint
{
    public function __construct(private readonly ProjectBlueprintProviderRegistry $providers) {}

    public function handle(array $definition): array
    {
        if (strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 65536 || $this->containsSensitiveField($definition)) {
            throw ValidationException::withMessages(['definition' => __('Blueprints must be smaller than 64 KiB and contain configuration references only. Supply secrets, subscriptions, and verification through their app workflows.')]);
        }
        Validator::make(['definition' => $definition], [
            'definition' => ['required', 'array:schema_version,environments,products'],
            'definition.schema_version' => ['required', 'integer', Rule::in([1])],
            'definition.environments' => ['required', 'array', 'min:1', 'max:10'],
            'definition.environments.*' => ['required', 'array:key,name,type'],
            'definition.environments.*.key' => ['required', 'string', 'max:60', 'regex:/\A[a-z][a-z0-9-]*\z/', 'distinct:strict'],
            'definition.environments.*.name' => ['required', 'string', 'max:120'],
            'definition.environments.*.type' => ['required', Rule::in(['production', 'staging', 'development', 'preview', 'custom'])],
            'definition.products' => ['required', 'array:deployer,monitor,analytics', 'min:1', 'max:3'],
            'definition.products.*' => ['required', 'array'],
        ])->validate();
        if (! array_is_list($definition['environments'])) {
            throw ValidationException::withMessages(['definition.environments' => __('Use an ordered list of environment definitions.')]);
        }
        $products = [];
        foreach ($definition['products'] as $product => $configuration) {
            $provider = $this->providers->get($product);
            if ($provider === null) {
                throw ValidationException::withMessages(['definition.products.'.$product => __('This app does not currently provide blueprint support.')]);
            }
            $products[$product] = $provider->normalize($configuration);
        }
        ksort($products);

        return ['schema_version' => 1, 'environments' => array_map(fn (array $environment): array => [
            'key' => $environment['key'], 'name' => trim($environment['name']), 'type' => $environment['type'],
        ], $definition['environments']), 'products' => $products];
    }

    private function containsSensitiveField(array $value): bool
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/password|secret|token|credential|authorization|api[_-]?key|private[_-]?key|verified|subscription|billing/i', $key)) {
                return true;
            }
            if (is_array($item) && $this->containsSensitiveField($item)) {
                return true;
            }
        }

        return false;
    }
}
