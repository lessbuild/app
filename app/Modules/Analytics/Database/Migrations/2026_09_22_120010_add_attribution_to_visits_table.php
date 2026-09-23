<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('analytics')->table('visits', function (Blueprint $table): void {
            $table->string('entry_referrer_host', 255)->nullable()->after('exit_path');
            $table->string('entry_utm_source', 100)->nullable()->after('entry_referrer_host');
            $table->string('entry_utm_medium', 100)->nullable()->after('entry_utm_source');
            $table->string('entry_utm_campaign', 150)->nullable()->after('entry_utm_medium');
            $table->index(['site_id', 'entry_utm_source', 'started_at']);
            $table->index(['site_id', 'entry_referrer_host', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('analytics')->table('visits', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'entry_utm_source', 'started_at']);
            $table->dropIndex(['site_id', 'entry_referrer_host', 'started_at']);
            $table->dropColumn(['entry_referrer_host', 'entry_utm_source', 'entry_utm_medium', 'entry_utm_campaign']);
        });
    }
};
