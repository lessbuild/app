<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'monitor';

    public function up(): void
    {
        Schema::connection('monitor')->table('users', function (Blueprint $table): void {
            $table->ulid('platform_user_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('users', function (Blueprint $table): void {
            $table->dropUnique(['platform_user_id']);
            $table->dropColumn('platform_user_id');
        });
    }
};
