<?php

namespace App\Contracts\Scripts;

use App\Models\Build;

interface BuildScript
{
    /**
     * Render a provisioning shell fragment for the selected build and callback stage.
     *
     * @param  int  $step  Lifecycle stage reported by this fragment.
     * @param  Build  $build  Target whose settings and callback identity the script uses.
     * @return string Shell commands ready to include in the provisioning script.
     */
    public function script(int $step, Build $build): string;
}
