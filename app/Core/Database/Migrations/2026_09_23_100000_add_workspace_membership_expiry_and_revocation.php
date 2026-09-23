<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->table('workspace_memberships', function (Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->after('status');
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::connection('core')->table('workspace_memberships', function (Blueprint $table): void {
            $table->dropColumn(['expires_at', 'revoked_at']);
        });
    }
};
