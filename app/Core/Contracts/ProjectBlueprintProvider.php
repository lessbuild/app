<?php

namespace App\Core\Contracts;

use App\Core\Data\Blueprints\BlueprintProductPreview;
use App\Core\Data\Blueprints\BlueprintProductResult;
use App\Core\Data\Blueprints\BlueprintStepAttempt;
use App\Core\Data\Blueprints\BlueprintTarget;

interface ProjectBlueprintProvider
{
    /** A secret-free example for the versioned definition editor. */
    public function example(): array;

    /** Validate the versioned product configuration without reads, writes, secrets, or provider calls. */
    public function normalize(array $configuration): array;

    /** Resolve current authority, native bindings, and plan impact without mutating any database. */
    public function preview(BlueprintTarget $target, array $configuration): BlueprintProductPreview;

    /**
     * Recheck Core authority and native policies/fences under native target locks.
     * Commit local provisioning with a receipt keyed by step ID and immutable payload hash.
     * A replay returns that receipt; no external deployment, purchase, or verification is implicit.
     */
    public function apply(BlueprintStepAttempt $attempt): BlueprintProductResult;
}
