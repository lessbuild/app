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
        Schema::connection('analytics')->create('site_release_annotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('delivery_id', 26);
            $table->string('handler', 100);
            $table->string('project_connection_id', 26);
            $table->uuid('deployment_id');
            $table->string('source_build_id', 64);
            $table->string('version', 128);
            $table->string('revision', 64)->nullable();
            $table->timestamp('deployed_at', 6);
            $table->char('payload_hash', 64);
            $table->timestamps(6);
            $table->unique(['delivery_id', 'handler'], 'site_release_annotation_delivery_handler_unique');
            $table->unique(['site_id', 'deployment_id'], 'site_release_annotation_deployment_unique');
            $table->index(['site_id', 'deployed_at'], 'site_release_annotation_timeline_idx');
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->dropIfExists('site_release_annotations');
    }
};
