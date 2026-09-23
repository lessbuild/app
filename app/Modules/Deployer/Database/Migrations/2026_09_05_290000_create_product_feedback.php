<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 20);
            $table->string('severity', 20);
            $table->string('status', 20)->default('open');
            $table->string('title', 160);
            $table->text('description');
            $table->text('reproduction_steps')->nullable();
            $table->text('review_response')->nullable();
            $table->string('page', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_feedback');
    }
};
