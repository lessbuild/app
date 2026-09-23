<?php

namespace App\Modules\Deployer\Database;

use Illuminate\Database\Eloquent\Model;

abstract class DeployerModel extends Model
{
    protected $connection = 'deployer';

    /**
     * Keep the established single-connection SQLite test suite working while
     * production code opts into the named Deployer database connection.
     */
    public function getConnectionName(): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        return parent::getConnectionName();
    }
}
