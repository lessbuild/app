<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A site's scheduled reports (weekly or monthly) and traffic spike alerts, each to an email address or Slack. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('analytics_sites')->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 16);
            $table->string('channel', 16);
            $table->text('target');
            $table->unsignedInteger('threshold')->nullable();
            $table->string('last_period', 16)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['kind', 'site_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_notifications');
    }
};
