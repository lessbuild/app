<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('provisioning_status')->default('queued')->after('setup_stage');
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_error');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->string('provisioning_status')->default('queued')->after('setup_stage');
            $table->text('provisioning_error')->nullable()->after('provisioning_status');
            $table->timestamp('provisioned_at')->nullable()->after('provisioning_error');
            $table->text('database_password')->nullable()->after('environment');
        });

        DB::table('servers')->where('setup_stage', '>', 0)->update(['provisioning_status' => 'active']);
        DB::table('websites')->where('setup_stage', '>', 0)->update(['provisioning_status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'provisioning_error', 'provisioned_at']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['provisioning_status', 'provisioning_error', 'provisioned_at', 'database_password']);
        });
    }
};
