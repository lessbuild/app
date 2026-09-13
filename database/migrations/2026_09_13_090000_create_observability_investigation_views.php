<?php

use App\Models\Environment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observability_investigation_views', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignIdFor(Organization::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Environment::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['organization_id', 'expires_at']);
            $table->index(['environment_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observability_investigation_views');
    }
};
