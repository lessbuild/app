<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let database copies (and so preview databases) be full, a sample of each table, or the schema only.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('database_clones', function (Blueprint $table): void {
            $table->string('mode', 10)->default('full');
        });
        Schema::table('repositories', function (Blueprint $table): void {
            $table->string('preview_database_mode', 10)->default('full');
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('database_clones', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('preview_database_mode');
        });
    }
};
