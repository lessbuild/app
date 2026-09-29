<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finer access: a member can be limited to some projects, and environments can be protected so only owners, admins
 * and members allowed to deploy protected environments can deploy to them or change them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table): void {
            $table->json('project_ids')->nullable();
            $table->boolean('deploy_protected')->default(false);
        });
        Schema::table('environments', function (Blueprint $table): void {
            $table->boolean('protected')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->dropColumn('protected');
        });
        Schema::table('memberships', function (Blueprint $table): void {
            $table->dropColumn(['project_ids', 'deploy_protected']);
        });
    }
};
