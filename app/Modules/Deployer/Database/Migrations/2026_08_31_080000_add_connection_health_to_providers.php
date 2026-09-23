<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->string('connection_status')->nullable()->after('token');
            $table->timestamp('connection_checked_at')->nullable()->after('connection_status');
            $table->index(['user_id', 'connection_status']);
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'connection_status']);
            $table->dropColumn(['connection_status', 'connection_checked_at']);
        });
    }
};
