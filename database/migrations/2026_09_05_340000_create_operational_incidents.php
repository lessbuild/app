<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 40);
            $table->unsignedBigInteger('resource_id');
            $table->string('active_key')->nullable()->unique();
            $table->string('status', 20)->default('open');
            $table->string('severity', 20)->default('major');
            $table->string('title');
            $table->text('summary');
            $table->text('resolution')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('detected_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'last_seen_at']);
            $table->index(['organization_id', 'category', 'resource_id', 'status'], 'operational_incident_resource_status');
        });

        Schema::create('operational_incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operational_incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['operational_incident_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_incident_events');
        Schema::dropIfExists('operational_incidents');
    }
};
