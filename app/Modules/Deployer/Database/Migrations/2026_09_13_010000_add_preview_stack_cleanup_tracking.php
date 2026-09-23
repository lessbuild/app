<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_processes', function (Blueprint $table): void {
            $table->boolean('is_preview_owned')->default(false);
        });

        Schema::table('environment_resources', function (Blueprint $table): void {
            $table->boolean('is_preview_owned')->default(false);
        });

        Schema::create('preview_stack_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preview_deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('deployment_slug', 32);
            $table->json('process_manifest')->nullable();
            $table->json('resource_manifest')->nullable();
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['preview_deployment_id', 'environment_id']);
            $table->index(['preview_deployment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preview_stack_cleanups');

        Schema::table('environment_resources', function (Blueprint $table): void {
            $table->dropColumn('is_preview_owned');
        });

        Schema::table('environment_processes', function (Blueprint $table): void {
            $table->dropColumn('is_preview_owned');
        });
    }
};
