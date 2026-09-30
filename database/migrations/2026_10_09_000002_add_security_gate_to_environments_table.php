<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The lowest severity of known vulnerability that stops an environment's deploys (null lets everything through). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('security_gate', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn('security_gate'));
    }
};
