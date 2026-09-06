<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('viewer');
            $table->timestamps();
            $table->primary(['organization_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('organization_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 20);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'email']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $base = Str::slug($user->name ?: Str::before($user->email ?: 'workspace', '@')) ?: 'workspace';
            $slug = $base.'-'.$user->id;
            $organizationId = DB::table('organizations')->insertGetId([
                'owner_id' => $user->id,
                'name' => ($user->name ?: 'Personal').' Workspace',
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('organization_user')->insert([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('users')->where('id', $user->id)->update(['current_organization_id' => $organizationId]);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('current_organization_id'));
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
