<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->json('auto_deploy_include_paths')->nullable()->after('branch');
            $table->json('auto_deploy_exclude_paths')->nullable()->after('auto_deploy_include_paths');
        });

        Schema::table('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->json('changed_paths')->nullable()->after('commit_message');
        });
    }

    public function down(): void
    {
        Schema::table('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('changed_paths');
        });

        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['auto_deploy_include_paths', 'auto_deploy_exclude_paths']);
        });
    }
};
