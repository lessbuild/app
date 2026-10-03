<?php

declare(strict_types=1);

namespace App\Services\Infrastructure\Scripts\Website;

use App\Models\Website;

final class WriteEnvFileScript extends WebsiteProvisioningScript
{
    /**
     * Write the website's `.env` file, passing its contents base64-encoded so nothing in them is interpreted by the
     * shell.
     *
     * @param  int  $step
     * @param  Website  $website
     * @return string
     */
    public function script(int $step, Website $website): string
    {
        $directory = escapeshellarg("/var/www/{$website->deployment_slug}");
        $path = escapeshellarg("/var/www/{$website->deployment_slug}/.env");
        $contents = escapeshellarg(base64_encode((string) $website->env_file));
        $progress = $this->progress($step, $website);

        return <<<SCRIPT
        mkdir -p -- {$directory}
        printf '%s' {$contents} | base64 --decode > {$path}
        {$progress}
        SCRIPT;
    }
}
