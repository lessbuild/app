<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remember which tracker an issue's ticket is in, so its status can be followed.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->foreignId('ticket_tracker_id')->nullable()->constrained('issue_trackers')->nullOnDelete();
        });
    }

    /**
     * Forget it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ticket_tracker_id');
        });
    }
};
