<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->unsignedSmallInteger('setup_stage')->default(0)->after('status');
        });

        // Historical successful builds reached the end of the deployment
        // plan even though older releases only stored progress globally.
        DB::table('builds')->where('status', 'succeeded')->update(['setup_stage' => 15]);
    }

    public function down(): void
    {
        Schema::table('builds', fn (Blueprint $table) => $table->dropColumn('setup_stage'));
    }
};
