<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Settings the platform keeps about itself (such as where it monitors itself), stored encrypted. */
return new class extends Migration
{
    /**
     * Create the platform settings table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->timestamps(6);
        });
    }

    /**
     * Drop the platform settings table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
