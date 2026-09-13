<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_troubleshooting_sessions', function (Blueprint $table): void {
            $table->char('broker_lease_hash', 64)->nullable()->after('grant_hash');
            $table->timestamp('broker_lease_expires_at')->nullable()->after('broker_lease_hash');
            $table->unsignedInteger('broker_attempt')->default(0)->after('broker_lease_expires_at');
            $table->unsignedBigInteger('broker_process_id')->nullable()->after('broker_attempt');
            $table->unsignedBigInteger('input_sequence')->default(0)->after('broker_process_id');
            $table->unsignedBigInteger('output_sequence')->default(0)->after('input_sequence');
            $table->index(['status', 'broker_lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('server_troubleshooting_sessions', function (Blueprint $table): void {
            $table->dropIndex('server_troubleshooting_sessions_status_broker_lease_expires_at_index');
            $table->dropColumn([
                'broker_lease_hash',
                'broker_lease_expires_at',
                'broker_attempt',
                'broker_process_id',
                'input_sequence',
                'output_sequence',
            ]);
        });
    }
};
