<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string */
    protected $connection = 'analytics';

    public function up(): void
    {
        Schema::connection('analytics')->create('site_incident_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('delivery_id', 26);
            $table->string('handler', 100);
            $table->string('project_connection_id', 26);
            $table->string('source_incident_id', 64);
            $table->string('status', 24);
            $table->timestamp('occurred_at', 6);
            $table->char('payload_hash', 64);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler'], 'site_incident_annotation_delivery_handler_unique');
            $table->index(['site_id', 'occurred_at'], 'site_incident_annotation_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('site_incident_annotations');
    }
};
