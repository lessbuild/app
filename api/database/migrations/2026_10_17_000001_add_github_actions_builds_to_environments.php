<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let an environment's deploys build on GitHub Actions, so a small server only unpacks and runs the result.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('github_actions_builds')->default(false)->after('artifact_bucket_id');
        });
    }

    /**
     * Build every deploy on a server again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('github_actions_builds');
        });
    }
};
