<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('issue_digest_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('frequency', 16)->default('daily');
            $table->timestamps(6);
            $table->unique(['workspace_id', 'user_id'], 'issue_digest_preferences_workspace_user_unique');
            $table->index(['workspace_id', 'enabled', 'frequency'], 'issue_digest_preferences_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('issue_digest_preferences');
    }
};
