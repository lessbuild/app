<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table) {
            $table->string('match_text', 120)->nullable()->after('service');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('monitor')->table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('match_text');
        });
    }
};
