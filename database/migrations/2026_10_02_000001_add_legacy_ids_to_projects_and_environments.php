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
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('legacy_id'));
        }
    }
};
