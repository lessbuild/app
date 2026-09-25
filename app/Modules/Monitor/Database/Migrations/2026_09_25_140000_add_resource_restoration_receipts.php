<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'monitor';

    public function up(): void
    {
        Schema::connection('monitor')->table('applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('lifecycle_revision')->default(0);
        });
        Schema::connection('monitor')->create('resource_restoration_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 26)->unique();
            $table->foreignId('application_id')->constrained('applications')->restrictOnDelete();
            $table->string('resource_type', 32);
            $table->string('resource_id', 64);
            $table->char('payload_hash', 64);
            $table->char('mapping_fingerprint', 64);
            $table->unsignedBigInteger('expected_revision');
            $table->unsignedBigInteger('revision');
            $table->json('states');
            $table->char('receipt_hash', 64);
            $table->timestamp('created_at', 6);
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->dropIfExists('resource_restoration_receipts');
        Schema::connection('monitor')->table('applications', fn (Blueprint $table) => $table->dropColumn('lifecycle_revision'));
    }
};
