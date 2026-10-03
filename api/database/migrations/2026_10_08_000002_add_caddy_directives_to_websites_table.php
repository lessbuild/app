<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A website's own Caddy directives (headers, redirects, rewrites…), and why the last config change was refused. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->text('caddy_directives')->nullable();
            $table->text('caddy_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn(['caddy_directives', 'caddy_error']));
    }
};
