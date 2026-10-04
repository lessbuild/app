<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveBackupSchedule;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreBackupScheduleController
{
    /**
     * Add a daily or weekly backup schedule to a website.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  SaveBackupSchedule  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Website $website, SaveBackupSchedule $save): JsonResponse
    {
        /** @var array{backup_destination_id: string, frequency: string, weekday?: string|null, run_at: string, retention_count: string, secondary_destination_id?: string|null} $data */
        $data = $request->validate([
            'backup_destination_id' => ['required', 'integer'],
            'frequency' => ['required', 'in:daily,weekly'],
            'weekday' => ['nullable', 'required_if:frequency,weekly', 'integer', 'between:0,6'],
            'run_at' => ['required', 'date_format:H:i'],
            'retention_count' => ['required', 'integer', 'between:1,365'],
            'secondary_destination_id' => ['nullable', 'integer'],
        ]);
        $save->handle($user, $website, [...$data, 'monthly_drill' => $request->boolean('monthly_drill', true)]);

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'], false), 'message' => __('Backup schedule saved.')]);
    }
}
