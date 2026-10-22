<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets a website go without its own MySQL database (a Node.js, Python or static site); existing ones keep theirs. */
return new class extends Migration
{
    /**
     * Add the column.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->boolean('has_database')->default(true);
        });
    }

    /**
     * Remove it again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('has_database');
        });
    }
};
