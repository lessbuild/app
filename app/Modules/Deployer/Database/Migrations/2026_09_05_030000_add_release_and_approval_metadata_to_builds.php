<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->string('release_name')->nullable();
            $table->string('release_path')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('rolled_back_from_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->index(['environment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropIndex(['environment_id', 'status']);
            foreach (['environment_id', 'requested_by', 'approved_by', 'rejected_by', 'rolled_back_from_build_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['approved_at', 'rejected_at', 'approval_note', 'release_name', 'release_path', 'activated_at']);
        });
    }
};
