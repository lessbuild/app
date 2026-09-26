<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection('monitor')->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new LogicException('Stable queue identifiers require SQLite, MySQL, MariaDB, or PostgreSQL.');
        }

        Schema::connection('monitor')->table('jobs', function (Blueprint $table) use ($driver): void {
            $column = $table->string('telemetry_uuid', 36)->nullable();

            if ($driver === 'pgsql') {
                $column->storedAs("payload::jsonb ->> 'uuid'");
            } else {
                $column->virtualAsJson('payload->uuid');
            }

            $table->index(['queue', 'telemetry_uuid'], 'jobs_queue_telemetry_uuid_index');
        });
        Schema::connection('monitor')->table('ingest_receipts', function (Blueprint $table): void {
            $table->uuid('queue_job_uuid')->nullable();
        });

        DB::connection('monitor')->table('ingest_receipts')->whereNotNull('queue_job_id')->orderBy('id')
            ->chunkById(100, function (Collection $receipts): void {
                $jobs = DB::connection('monitor')->table('jobs')->where('queue', 'telemetry')
                    ->whereIn('id', $receipts->pluck('queue_job_id'))->pluck('telemetry_uuid', 'id');

                foreach ($receipts as $receipt) {
                    DB::connection('monitor')->table('ingest_receipts')->where('id', $receipt->id)
                        ->update(['queue_job_uuid' => $jobs->get($receipt->queue_job_id)]);
                }
            });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('ingest_receipts', function (Blueprint $table): void {
            $table->dropColumn('queue_job_uuid');
        });
        Schema::connection('monitor')->table('jobs', function (Blueprint $table): void {
            $table->dropIndex('jobs_queue_telemetry_uuid_index');
            $table->dropColumn('telemetry_uuid');
        });
    }
};
