<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An incident's post-mortem, and the status page report it was published as. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->json('postmortem')->nullable();
            $table->foreignId('postmortem_status_update_id')->nullable()->constrained('status_updates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('postmortem_status_update_id');
            $table->dropColumn('postmortem');
        });
    }
};
