<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->create('ingest_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->char('receipt_key', 64)->unique();
            $table->char('payload_fingerprint', 64);
            $table->string('source', 32);
            $table->string('status', 16);
            $table->unsignedInteger('event_count');
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('attempt_count')->default(1);
            $table->timestamp('received_at', 6);
            $table->timestamp('last_received_at', 6);
            $table->timestamp('processed_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['environment_id', 'received_at', 'id'], 'receipts_environment_received_index');
        });

        Schema::connection('monitor')->create('telemetry_event_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('ingest_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('telemetry_event_id')->nullable()->constrained()->nullOnDelete();
            $table->char('dedupe_key', 64)->unique();
            $table->unsignedTinyInteger('version');
            $table->char('payload_fingerprint', 64)->nullable();
            $table->timestamps(6);
        });

        Schema::connection('monitor')->create('telemetry_usage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('ingest_receipt_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('source', 32);
            $table->unsignedInteger('event_count');
            $table->timestamp('received_at', 6);
            $table->timestamps(6);
            $table->index(['workspace_id', 'received_at'], 'usage_workspace_received_index');
        });

        DB::connection('monitor')->table('telemetry_events')->select(['id', 'environment_id', 'dedupe_key', 'created_at'])
            ->chunkById(100, function (Collection $events): void {
                DB::connection('monitor')->table('telemetry_event_identities')->insert($events->map(fn (object $event): array => [
                    'environment_id' => $event->environment_id,
                    'telemetry_event_id' => $event->id,
                    'dedupe_key' => $event->dedupe_key,
                    'version' => 1,
                    'created_at' => $event->created_at,
                    'updated_at' => $event->created_at,
                ])->all());
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('telemetry_usage_entries');
        Schema::connection('monitor')->dropIfExists('telemetry_event_identities');
        Schema::connection('monitor')->dropIfExists('ingest_receipts');
    }
};
