<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marks sample projects: made-up data to look around with, labelled as such and safe to delete. */
return new class extends Migration
{
    /**
     * Add the sample flag.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('is_sample')->default(false);
        });
    }

    /**
     * Drop the sample flag.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn('is_sample');
        });
    }
};
