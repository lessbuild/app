<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6: Deployer's numeric project and environment IDs, filled by the importer, so old API paths keep working. */
return new class extends Migration
{
    /**
     * Add the legacy IDs.
     *
     * @return void
     */
    public function up(): void
    {
        foreach (['projects', 'environments'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
            });
        }
    }

    /**
     * Drop the legacy IDs.
     *
     * @return void
     */
    public function down(): void
    {
        foreach (['projects', 'environments'] as $name) {
            // The unique index goes first: SQLite can't drop a column an index still uses.
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropUnique("{$name}_legacy_id_unique");
                $table->dropColumn('legacy_id');
            });
        }
    }
};
