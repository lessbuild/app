<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Which alert destinations hear about an environment's deploys, and for which outcomes. */
return new class extends Migration
{
    /**
     * Create the deploy notification routes table.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('environment_deploy_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alert_destination_id')->constrained()->cascadeOnDelete();
            $table->boolean('on_success')->default(true);
            $table->boolean('on_failure')->default(true);
            $table->boolean('on_approval')->default(false);
            $table->timestamps();
            $table->unique(['environment_id', 'alert_destination_id']);
        });
    }

    /**
     * Drop the deploy notification routes table.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('environment_deploy_notifications');
    }
};
