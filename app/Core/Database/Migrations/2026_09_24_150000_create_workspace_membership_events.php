<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('workspace_membership_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('membership_id')->nullable()->constrained('workspace_memberships')->nullOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 48);
            $table->string('previous_role', 32)->nullable();
            $table->string('new_role', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['membership_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_membership_events');
    }
};
