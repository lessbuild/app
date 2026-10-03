<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Marks an environment, process, resource or variable as managed by configuration under a logical name, so later
 * documents update it, and removing it from a document can delete it; anything else is left alone unless adopted.
 *
 * @property int $id
 * @property string $project_id
 * @property int|null $configuration_review_id
 * @property string $environment_slug
 * @property string $kind environment, processes, resources or variables
 * @property string $logical_name
 * @property string $resource_key the managed record's ID
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class ConfigurationOwnership extends Model {}
