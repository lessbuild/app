<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->uuid('provisioning_token')->nullable()->after('provisioning_error');
            $table->string('provisioning_failure_phase')->nullable()->after('provisioning_token');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn(['provisioning_token', 'provisioning_failure_phase']);
        });
    }
};
