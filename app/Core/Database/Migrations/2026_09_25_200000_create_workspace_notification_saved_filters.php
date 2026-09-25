<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('workspace_notification_saved_filters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('filters');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id', 'name'], 'workspace_notification_saved_filters_unique');
            $table->index(['workspace_id', 'user_id', 'updated_at'], 'workspace_notification_saved_filters_user_index');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('workspace_notification_saved_filters');
    }
};
