<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the ad accounts an Analytics site reads its ad spend from (Google Ads, Meta).
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('analytics_ad_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('account_id', 40);
            $table->string('name', 200);
            $table->string('source', 100);
            $table->text('credential');
            $table->foreignUlid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'platform', 'account_id']);
        });
    }

    /**
     * Drop the ad accounts.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_ad_accounts');
    }
};
