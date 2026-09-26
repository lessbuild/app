<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->ulidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            // Set once the owner has been told the token expires soon, so they are told once.
            $table->timestamp('expiry_warned_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('expiry_warned_at'));
        Schema::dropIfExists('notifications');
    }
};
