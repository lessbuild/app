<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let database copies mask personal data, for staging and previews.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('database_clones', function (Blueprint $table): void {
            $table->boolean('anonymise')->default(false);
        });
        Schema::table('repositories', function (Blueprint $table): void {
            $table->boolean('preview_database_anonymise')->default(false);
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
            $table->dropColumn('anonymise');
        });
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropColumn('preview_database_anonymise');
        });
    }
};
