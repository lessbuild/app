<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\BackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BackupDestination> */
class BackupDestinationFactory extends Factory
{
    protected $model = BackupDestination::class;

    /**
     * A DigitalOcean Spaces bucket.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => 'Offsite backups',
            'storage_provider' => 'digitalocean_spaces',
            'endpoint' => 'https://ams3.digitaloceanspaces.com',
            'bucket' => 'shop-backups',
            'region' => 'ams3',
            'access_key' => 'DO00ACCESS',
            'secret_key' => 'spaces-secret',
            'repository_password' => 'restic-password',
            'path_prefix' => 'buildpusher',
        ];
    }
}
