<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->create('status_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamps(6);
            $table->index(['workspace_id', 'created_at', 'id'], 'status_pages_workspace_created_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('status_pages');
    }
};
