<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referrals: each account's share code, who signed up with one, and the credits both sides earn once the new account
 * pays. Credits wait as pending until the account has a Stripe customer to hold them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('referral_code', 16)->nullable()->unique();
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('referrer_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('referred_account_id')->unique()->constrained('accounts')->cascadeOnDelete();
            $table->foreignUlid('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamps();
            $table->index(['referrer_account_id', 'qualified_at']);
        });

        Schema::create('referral_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('status', 16);
            $table->string('provider_reference')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->unique(['referral_id', 'account_id']);
            $table->index(['status', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_credits');
        Schema::dropIfExists('referrals');
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }
};
