<?php

declare(strict_types=1);

namespace App\Contracts\Deploy;

use App\Models\Build;

/**
 * One stage of a deploy's shell script. Each script also names its stage with constants: TITLE (shown on the deploy
 * page), DESCRIPTION, and IDENTIFIER (the stage's stable name, as Deployer called it).
 */
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
