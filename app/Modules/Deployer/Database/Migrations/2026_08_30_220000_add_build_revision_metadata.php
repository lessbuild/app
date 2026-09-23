<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->string('trigger_source')->default('manual')->after('status');
            $table->string('revision', 64)->nullable()->after('trigger_source');
            $table->text('commit_message')->nullable()->after('revision');
        });

        Schema::table('repositories', function (Blueprint $table): void {
            $table->string('webhook_pending_revision', 64)->nullable()->after('webhook_pending');
            $table->text('webhook_pending_commit_message')->nullable()->after('webhook_pending_revision');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['webhook_pending_revision', 'webhook_pending_commit_message']);
        });

        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn(['trigger_source', 'revision', 'commit_message']);
        });
    }
};
