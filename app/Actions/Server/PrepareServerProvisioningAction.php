<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Scripts\Database\InstallMysqlScript;
use App\Services\ServerProvisioningPlan;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class PrepareServerProvisioningAction
{
    /**
     * Use the provisioning plan to determine whether managed MySQL credentials are needed.
     *
     * @param  ServerProvisioningPlan  $plan  Ordered provisioning or deployment plan defining the steps to render.
     */
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

    /**
     * Generate the root credential for provisioning and flash it to the session; persist and flash a MySQL root password when the plan requires one.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     */
    public function handle(Server $server): void
    {
        if (! $server->provisioningRootPassword()) {
            $server->setProvisioningRootPassword(Str::random(40));
            Session::flash('root_password', $server->provisioningRootPassword());
        }

        if (! in_array(InstallMysqlScript::class, $this->plan->steps($server), true)) {
            return;
        }

        if (! $server->mysql_root_password) {
            $server->update(['mysql_root_password' => Str::random(40)]);
        }

        Session::flash('mysql_password', $server->mysql_root_password);
    }
}
