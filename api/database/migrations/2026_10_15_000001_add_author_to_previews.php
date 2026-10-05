<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record who opened each preview: the pull request's author, or whoever opened a branch preview.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('previews', function (Blueprint $table): void {
            $table->string('author', 120)->nullable()->after('title');
        });
    }

    /**
     * Forget who opened previews.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('previews', function (Blueprint $table): void {
            $table->dropColumn('author');
        });
    }
};
