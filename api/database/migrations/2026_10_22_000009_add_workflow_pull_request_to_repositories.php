<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Keeps the pull request Buildpusher opened with a repository's GitHub Actions build workflow. */
return new class extends Migration
{
    /**
     * Add the column.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->string('workflow_pull_request_url', 500)->nullable();
        });
    }

    /**
     * Remove it again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('workflow_pull_request_url');
        });
    }
};
