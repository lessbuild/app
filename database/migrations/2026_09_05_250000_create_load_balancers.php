<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_balancers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('hostname')->unique();
            $table->string('health_path')->default('/');
            $table->string('status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::create('load_balancer_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('load_balancer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('upstream_port')->default(80);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->string('health_status', 20)->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['load_balancer_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_balancer_nodes');
        Schema::dropIfExists('load_balancers');
    }
};
