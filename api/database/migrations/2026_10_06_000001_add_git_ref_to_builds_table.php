<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A deploy can ask for a branch, tag or commit other than the repository's branch; the server resolves it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->string('git_ref', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropColumn('git_ref');
        });
    }
};
