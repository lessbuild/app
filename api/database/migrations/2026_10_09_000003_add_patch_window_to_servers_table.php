<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A weekly window for installing a server's security updates (and rebooting if they need it), and how the last run went. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('patch_day')->nullable();
            $table->unsignedTinyInteger('patch_hour')->default(3);
            $table->boolean('patch_reboot')->default(false);
            $table->timestamp('last_patched_at')->nullable();
            $table->text('last_patch_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn(['patch_day', 'patch_hour', 'patch_reboot', 'last_patched_at', 'last_patch_error']));
    }
};
