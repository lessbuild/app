<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A read-only link to a site's report that people without an account can open, optionally behind a password. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->string('share_token', 64)->nullable()->unique();
            $table->string('share_password')->nullable();
            $table->timestamp('shared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('analytics_sites', function (Blueprint $table): void {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'share_password', 'shared_at']);
        });
    }
};
