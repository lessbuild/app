<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Docker Compose runtime's web service: the one Caddy sends traffic to.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('compose_service', 63)->nullable();
        });
    }

    /**
     * Remove it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('compose_service');
        });
    }
};
