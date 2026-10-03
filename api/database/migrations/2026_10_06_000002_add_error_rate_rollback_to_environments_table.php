<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** While a deploy is watched, a jump in failed requests (from the app's own telemetry) can fail it and roll back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rollback_error_rate_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('rollback_error_rate_percent');
        });
    }
};
