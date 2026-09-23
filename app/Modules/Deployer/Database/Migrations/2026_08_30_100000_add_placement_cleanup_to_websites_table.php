<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->unsignedBigInteger('previous_server_id')->nullable()->after('server_id');
            $table->text('placement_cleanup_error')->nullable()->after('provisioning_error');
            $table->uuid('provisioning_token')->nullable()->after('placement_cleanup_error');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            $table->dropColumn(['previous_server_id', 'placement_cleanup_error', 'provisioning_token']);
        });
    }
};
