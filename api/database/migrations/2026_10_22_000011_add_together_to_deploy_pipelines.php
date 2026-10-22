<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets pipeline steps deploy side by side: the steps that start together with the one before them. */
return new class extends Migration
{
    /**
     * Add the column.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('deploy_pipelines', function (Blueprint $table): void {
            $table->json('together')->nullable();
        });
    }

    /**
     * Remove it again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('deploy_pipelines', function (Blueprint $table): void {
            $table->dropColumn('together');
        });
    }
};
