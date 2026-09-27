<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Infrastructure part 6: Caddy load balancers in front of application servers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_balancers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname', 253)->unique();
            $table->string('health_path')->default('/up');
            $table->string('status', 20)->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('applied_at', 6)->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable();
            $table->timestamps(6);
            $table->index('account_id');
        });

        Schema::create('load_balancer_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('load_balancer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('upstream_port')->default(80);
            $table->unsignedTinyInteger('weight')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps(6);
            $table->unique(['load_balancer_id', 'server_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_balancer_nodes');
        Schema::dropIfExists('load_balancers');
    }
};
