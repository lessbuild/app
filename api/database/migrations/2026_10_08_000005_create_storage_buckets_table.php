<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S3-compatible buckets a project stores files in, and the environment each one's settings were given to. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_buckets', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('environment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->string('storage_provider', 30);
            $table->string('endpoint');
            $table->string('region', 60);
            $table->string('bucket', 63);
            $table->text('access_key');
            $table->text('secret_key');
            $table->timestamp('verified_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'endpoint', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_buckets');
    }
};
