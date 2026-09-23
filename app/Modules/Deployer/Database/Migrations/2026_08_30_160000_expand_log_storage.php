<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs', function (Blueprint $table): void {
            $table->longText('log')->change();
        });
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table): void {
            $table->text('log')->change();
        });
    }
};
