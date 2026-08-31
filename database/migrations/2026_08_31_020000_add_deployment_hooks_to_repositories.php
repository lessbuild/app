<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->text('build_commands')->nullable()->after('branch');
            $table->text('post_deployment_commands')->nullable()->after('build_commands');
        });

        DB::table('repositories')
            ->where('setup_stage', '>=', 8)
            ->update(['setup_stage' => 10]);
    }

    public function down(): void
    {
        DB::table('repositories')
            ->where('setup_stage', '>=', 10)
            ->update(['setup_stage' => 8]);

        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['build_commands', 'post_deployment_commands']);
        });
    }
};
