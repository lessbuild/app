<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_command_executions', function (Blueprint $table): void {
            $table->foreignId('rerun_from_execution_id')
                ->nullable()
                ->after('status')
                ->constrained('server_command_executions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('server_command_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rerun_from_execution_id');
        });
    }
};
