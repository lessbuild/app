<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of moves from Laravel Forge or Ploi: the API token, what was read from the other tool, and
     * which of its sites have been recreated here.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('tool_moves', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 10);
            $table->text('token');
            $table->longText('inventory');
            $table->json('moved')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Drop it.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('tool_moves');
    }
};
