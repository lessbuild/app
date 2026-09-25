<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('deployer')->create('product_deletion_fences', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('source_id', 128);
            $table->string('request_id', 64);
            $table->string('payload_hash', 64);
            $table->unsignedInteger('generation');
            $table->string('state', 24)->default('prepared');
            $table->timestamps();
            $table->unique(['kind', 'source_id']);
            $table->index(['request_id', 'payload_hash']);
        });

        Schema::connection('deployer')->create('product_deletion_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('step_id', 64);
            $table->string('request_id', 64);
            $table->string('payload_hash', 64);
            $table->string('kind', 32);
            $table->string('source_id', 128);
            $table->string('phase', 16);
            $table->string('status', 24);
            $table->string('reason_code', 96)->nullable();
            $table->json('retained')->nullable();
            $table->timestamps();
            $table->unique(['step_id', 'request_id', 'payload_hash', 'phase']);
            $table->index(['kind', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('deployer')->dropIfExists('product_deletion_receipts');
        Schema::connection('deployer')->dropIfExists('product_deletion_fences');
    }
};
