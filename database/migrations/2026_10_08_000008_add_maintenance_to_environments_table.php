<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Maintenance mode for an environment's websites, and the secret link that still lets the team in. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->timestamp('maintenance_at')->nullable();
            $table->text('maintenance_secret')->nullable();
            $table->text('maintenance_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn(['maintenance_at', 'maintenance_secret', 'maintenance_error']));
    }
};
