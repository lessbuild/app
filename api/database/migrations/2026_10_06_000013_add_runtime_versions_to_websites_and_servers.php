<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each website can run its own PHP version; each server's Node.js version can be switched. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->string('php_version', 4)->nullable();
        });
        Schema::table('servers', function (Blueprint $table): void {
            $table->string('node_version', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('node_version');
        });
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn('php_version');
        });
    }
};
