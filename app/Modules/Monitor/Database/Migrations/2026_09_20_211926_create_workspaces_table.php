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
        Schema::connection('monitor')->create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('plan')->default('free');
            $table->timestamps();
        });

        Schema::connection('monitor')->create('user_workspace', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::connection('monitor')->table('applications', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
        });

        if (DB::connection('monitor')->table('applications')->exists()) {
            $owner = DB::connection('monitor')->table('users')->orderBy('id')->first();
            if ($owner === null) {
                throw new RuntimeException('An owner is required to migrate existing applications.');
            }

            $workspaceId = DB::connection('monitor')->table('workspaces')->insertGetId([
                'owner_id' => $owner->id,
                'name' => 'Acme Systems',
                'slug' => 'acme-'.Str::uuid(),
                'plan' => 'free',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('monitor')->table('user_workspace')->insert([
                'workspace_id' => $workspaceId,
                'user_id' => $owner->id,
                'role' => 'owner',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection('monitor')->table('applications')->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);

            if ($owner->email === 'sam@beacon.test' && password_verify('password', $owner->password)) {
                DB::connection('monitor')->table('users')->where('id', $owner->id)->update([
                    'password' => password_hash(Str::random(64), PASSWORD_BCRYPT),
                    'remember_token' => null,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::connection('monitor')->table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
        Schema::connection('monitor')->dropIfExists('user_workspace');
        Schema::connection('monitor')->dropIfExists('workspaces');
    }
};
