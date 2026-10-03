<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Package-manager downloads are cached on the server between deploys; clearing bumps the version. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('build_cache_enabled')->default(true);
            $table->unsignedInteger('build_cache_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn(['build_cache_enabled', 'build_cache_version']);
        });
    }
};
