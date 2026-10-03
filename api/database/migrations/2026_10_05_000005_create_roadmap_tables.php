<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The public roadmap: requests written up from feedback, with people's votes. Feedback stays private; a request links
 * the feedback it came from so its sender counts as a voter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('status', 20)->index();
            $table->unsignedInteger('votes_count')->default(0);
            $table->timestamp('shipped_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('feature_request_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feature_request_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['feature_request_id', 'user_id']);
        });

        Schema::table('feedback', function (Blueprint $table): void {
            $table->foreignId('feature_request_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('feature_request_id');
        });
        Schema::dropIfExists('feature_request_votes');
        Schema::dropIfExists('feature_requests');
    }
};
