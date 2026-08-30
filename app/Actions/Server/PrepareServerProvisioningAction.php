<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Scripts\Database\InstallMysqlScript;
use App\Services\ServerProvisioningPlan;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class PrepareServerProvisioningAction
{
    public function __construct(private readonly ServerProvisioningPlan $plan) {}

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
