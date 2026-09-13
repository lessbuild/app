<?php

namespace App\Data;

class PreviewStack
{
    /**
     * Describe the safe, local declarations that a supported preview preset requires.
     *
     * @param  list<array{name: string, type: string, command: string}>  $processes  Process definitions copied from the selected application template.
     * @param  list<array{name: string, type: string, is_managed: bool}>  $resources  Managed resource declarations without plaintext credentials.
     */
    public function __construct(
        public readonly array $processes,
        public readonly array $resources,
    ) {}
}
