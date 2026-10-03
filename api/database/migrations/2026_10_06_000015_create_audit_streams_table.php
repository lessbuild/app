<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit log entries sent as they happen to a Slack channel, a signed webhook or S3-compatible storage. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_streams', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->text('endpoint_url')->nullable();
            $table->text('signing_secret')->nullable();
            $table->foreignId('backup_destination_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_streams');
    }
};
