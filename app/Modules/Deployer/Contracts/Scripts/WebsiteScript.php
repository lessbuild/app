<?php

namespace App\Modules\Deployer\Contracts\Scripts;

use App\Modules\Deployer\Models\Website;

interface WebsiteScript
{
    /**
     * Render a provisioning shell fragment for the selected website and callback stage.
     *
     * @param  int  $step  Lifecycle stage reported by this fragment.
     * @param  Website  $website  Target whose settings and callback identity the script uses.
     * @return string Shell commands ready to include in the provisioning script.
     */
    public function script(int $step, Website $website): string;
}
