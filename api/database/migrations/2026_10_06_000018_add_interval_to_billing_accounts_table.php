<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accounts pay monthly or yearly; every item on a subscription shares the interval. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table): void {
            $table->string('interval', 8)->default('month');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table): void {
            $table->dropColumn('interval');
        });
    }
};
