<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Previews can start with a copy of another website's database (a database branch per pull request). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table): void {
            $table->foreignId('preview_database_source_website_id')->nullable()->constrained('websites')->nullOnDelete();
        });
        Schema::table('previews', function (Blueprint $table): void {
            $table->timestamp('database_copied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('previews', fn (Blueprint $table) => $table->dropColumn('database_copied_at'));
        Schema::table('repositories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preview_database_source_website_id');
        });
    }
};
