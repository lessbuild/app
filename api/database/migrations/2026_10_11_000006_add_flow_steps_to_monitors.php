<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the steps of multi-step checks (encrypted, since they can hold a test account's password).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->text('flow_steps')->nullable();
        });
    }

    /**
     * Remove them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('flow_steps');
        });
    }
};
