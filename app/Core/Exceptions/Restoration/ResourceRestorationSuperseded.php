<?php

namespace App\Core\Exceptions\Restoration;

final class ResourceRestorationSuperseded extends ResourceRestorationBlocked
{
    public function __construct(string $reasonCode = 'source_revision_changed')
    {
        parent::__construct($reasonCode);
    }
}
