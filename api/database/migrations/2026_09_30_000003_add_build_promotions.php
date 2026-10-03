<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Deploy part 4a: promoting a succeeded build's commit to a later environment keeps the lineage. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->foreignId('promoted_from_build_id')->nullable()->constrained('builds')->nullOnDelete();
            $table->text('promotion_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('builds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promoted_from_build_id');
            $table->dropColumn('promotion_note');
        });
    }
};
