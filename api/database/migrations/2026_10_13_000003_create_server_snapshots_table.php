<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of provider snapshots taken before risky changes, and the server setting that turns them on.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('server_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 120);
            $table->string('provider_snapshot', 255)->nullable();
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['server_id', 'created_at']);
        });
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('snapshot_before_changes')->default(false);
        });
    }

    /**
     * Drop them.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropColumn('snapshot_before_changes');
        });
        Schema::dropIfExists('server_snapshots');
    }
};
