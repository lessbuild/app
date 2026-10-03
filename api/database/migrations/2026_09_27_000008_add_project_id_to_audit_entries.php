<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            // Set for changes inside a project, so its overview can show recent activity.
            // Kept (as null) when the project is deleted: the account's log still records what happened.
            $table->foreignUlid('project_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_entries', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
