<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->table('project_connections', function (Blueprint $table): void {
            $table->timestamp('automation_paused_at')->nullable()->after('disconnected_at');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('project_connections', function (Blueprint $table): void {
            $table->dropColumn('automation_paused_at');
        });
    }
};
