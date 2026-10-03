<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Let previews come from any branch, not only pull requests, with their own expiry date.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('previews', function (Blueprint $table): void {
            $table->unsignedInteger('pull_request_number')->nullable()->change();
            $table->timestamp('expires_at')->nullable();
        });
    }

    /**
     * Remove branch previews' expiry (branch previews themselves can't be undone into pull requests).
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('previews', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
    }
};
