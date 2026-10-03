<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A job's payload UUID as an indexed column, so monitor checks and alert
     * deliveries can find (and discard) the queued job they own.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::table('jobs', function (Blueprint $table) use ($driver): void {
            $column = $table->string('job_uuid', 36)->nullable();

            if ($driver === 'pgsql') {
                $column->storedAs("(payload::jsonb ->> 'uuid')");
            } else {
                $column->virtualAs("json_extract(payload, '$.uuid')");
            }

            $table->index(['queue', 'job_uuid']);
        });
    }

    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table): void {
            $table->dropIndex(['queue', 'job_uuid']);
            $table->dropColumn('job_uuid');
        });
    }
};
