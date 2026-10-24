<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Built-in server alert rules (disk and memory) that Buildpusher keeps on every server, so a server is watched without anyone configuring it. */
return new class extends Migration
{
    /**
     * Add the flag.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table): void {
            $table->boolean('built_in')->default(false)->after('server_id');
        });
    }

    /**
     * Remove it again.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('server_alert_rules', function (Blueprint $table): void {
            $table->dropColumn('built_in');
        });
    }
};
