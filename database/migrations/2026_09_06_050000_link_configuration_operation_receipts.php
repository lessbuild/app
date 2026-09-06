<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_operations', fn (Blueprint $table) => $table->string('intent_digest', 64)->nullable()->index());
        Schema::create('configuration_operation_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('configuration_application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('configuration_operation_id')->constrained()->cascadeOnDelete();
            $table->unique(['configuration_application_id', 'configuration_operation_id'], 'configuration_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_operation_receipts');
        Schema::table('configuration_operations', function (Blueprint $table): void {
            $table->dropIndex(['intent_digest']);
            $table->dropColumn('intent_digest');
        });
    }
};
