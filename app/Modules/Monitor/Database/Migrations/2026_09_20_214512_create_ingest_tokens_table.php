<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('monitor')->create('ingest_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->index(['environment_id', 'revoked_at']);
        });

        $demoHashes = array_map(fn (string $token): string => hash('sha256', $token), [
            'bcn_demo_polaris_prod',
            'bcn_demo_polaris_staging',
            'bcn_demo_atlas_prod',
            'bcn_demo_ledger_prod',
        ]);

        DB::connection('monitor')->table('environments')->orderBy('id')->chunkById(100, function (Collection $environments) use ($demoHashes): void {
            foreach ($environments as $environment) {
                DB::connection('monitor')->table('ingest_tokens')->insert([
                    'environment_id' => $environment->id,
                    'name' => 'Imported environment token',
                    'token_hash' => $environment->ingest_token_hash,
                    'prefix' => 'legacy',
                    'revoked_at' => in_array($environment->ingest_token_hash, $demoHashes, true) ? now() : null,
                    'created_at' => $environment->created_at ?? now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Schema::connection('monitor')->table('environments', function (Blueprint $table): void {
            $table->dropUnique(['ingest_token_hash']);
            $table->dropColumn('ingest_token_hash');
            $table->softDeletes();
        });
        Schema::connection('monitor')->table('applications', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Multiple credentials and their revocation history cannot be restored to the old single-token schema. Use a forward migration.');
    }
};
