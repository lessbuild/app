<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('core')->create('workspace_feedback', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('product', 24);
            $table->string('category', 20);
            $table->string('severity', 20)->default('normal');
            $table->string('status', 20)->default('open');
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'created_at']);
            $table->index(['workspace_id', 'product', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_feedback');
    }
};
