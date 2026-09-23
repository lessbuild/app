<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('github_id', '')->update(['github_id' => null]);

        $hasDuplicateGithubIds = DB::table('users')
            ->select('github_id')
            ->whereNotNull('github_id')
            ->groupBy('github_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateGithubIds) {
            throw new RuntimeException('Duplicate GitHub account IDs must be resolved before migrating.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('gitlab_id')->nullable()->unique()->after('github_id');
            $table->string('bitbucket_id')->nullable()->unique()->after('gitlab_id');
            $table->unique('github_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['github_id']);
            $table->dropUnique(['gitlab_id']);
            $table->dropUnique(['bitbucket_id']);
            $table->dropColumn(['gitlab_id', 'bitbucket_id']);
        });
    }
};
