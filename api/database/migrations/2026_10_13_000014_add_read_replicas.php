<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add read replicas: a database server can copy another one continuously, serve reads, and be promoted.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->foreignId('replica_of_server_id')->nullable()->constrained('servers')->nullOnDelete();
            $table->string('replication_status', 20)->nullable();
            $table->text('replication_password')->nullable();
            $table->unsignedInteger('replication_lag_seconds')->nullable();
            $table->timestamp('replication_checked_at')->nullable();
            $table->text('replication_error')->nullable();
        });
    }

    /**
     * Remove read replicas.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replica_of_server_id');
            $table->dropColumn(['replication_status', 'replication_password', 'replication_lag_seconds', 'replication_checked_at', 'replication_error']);
        });
    }
};
