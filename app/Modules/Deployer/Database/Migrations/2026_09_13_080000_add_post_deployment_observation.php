<?php

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('post_deployment_observation_minutes')
                ->nullable()
                ->after('requires_deployment_approval');
        });

        Schema::create('deployment_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Build::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Website::class)->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->string('revision', 64);
            $table->string('website_url');
            $table->string('health_check_path');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('successful_checks')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('next_check_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique('build_id');
            $table->index(['website_id', 'status']);
            $table->index(['status', 'next_check_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_observations');

        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('post_deployment_observation_minutes');
        });
    }
};
