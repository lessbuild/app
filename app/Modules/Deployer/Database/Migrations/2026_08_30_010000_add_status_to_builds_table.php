<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->string('status')->default('queued')->after('repository_id');
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('finished_at')->nullable()->after('built_at');
            $table->text('failure_message')->nullable()->after('finished_at');
        });

        DB::table('builds')
            ->whereNotNull('built_at')
            ->update([
                'status' => 'succeeded',
                'started_at' => DB::raw('built_at'),
                'finished_at' => DB::raw('built_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table) {
            $table->dropColumn(['status', 'started_at', 'finished_at', 'failure_message']);
        });
    }
};
