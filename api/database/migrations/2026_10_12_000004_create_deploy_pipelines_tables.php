<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create deploy pipelines (repositories deployed in order) and their runs.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('deploy_pipelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 80);
            $table->json('repository_ids');
            $table->timestamps();
        });
        Schema::create('deploy_pipeline_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('deploy_pipelines')->cascadeOnDelete();
            $table->foreignUlid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('running');
            $table->unsignedSmallInteger('step')->default(0);
            $table->json('build_ids');
            $table->string('failure', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('deploy_pipeline_runs');
        Schema::dropIfExists('deploy_pipelines');
    }
};
