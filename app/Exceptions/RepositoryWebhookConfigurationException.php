<?php

namespace App\Exceptions;

use RuntimeException;

class RepositoryWebhookConfigurationException extends RuntimeException
{
    /**
     * Identify the required integration configuration without coupling the collaborator to an HTTP response.
     */
    public function __construct()
    {
        parent::__construct('GitHub App webhook delivery is not configured.');
    }
}
