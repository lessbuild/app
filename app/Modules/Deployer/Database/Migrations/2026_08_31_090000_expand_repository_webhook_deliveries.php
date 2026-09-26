<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->string('revision', 64)->nullable()->after('delivery_id');
            $table->text('commit_message')->nullable()->after('revision');
            $table->foreignId('build_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->index(['repository_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('repository_webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['repository_id', 'status', 'id']);
            $table->dropConstrainedForeignId('build_id');
            $table->dropColumn(['revision', 'commit_message']);
        });
    }
};
