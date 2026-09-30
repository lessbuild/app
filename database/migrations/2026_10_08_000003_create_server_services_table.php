<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Services installed on a server with one click (Meilisearch, Typesense, Redis), with the key or password they were
 * given (encrypted), and whether a server trusts the account's other servers over the private network.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedSmallInteger('port');
            $table->text('secret');
            $table->string('listen', 10)->default('local');
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['server_id', 'kind']);
        });
        Schema::table('servers', function (Blueprint $table): void {
            $table->boolean('trust_private_network')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('servers', fn (Blueprint $table) => $table->dropColumn('trust_private_network'));
        Schema::dropIfExists('server_services');
    }
};
