<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table): void {
            $table->string('scope', 20)->default('runtime')->after('is_secret');
            $table->unsignedInteger('current_version')->default(1)->after('scope');
            $table->timestamp('rotated_at')->nullable()->after('current_version');
            $table->timestamp('rotation_due_at')->nullable()->after('rotated_at');
        });
        Schema::create('environment_variable_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('environment_variable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version');
            $table->text('value');
            $table->timestamps();
            $table->unique(['environment_variable_id', 'version']);
        });

        DB::table('environment_variables')->orderBy('id')->each(function ($variable): void {
            DB::table('environment_variable_versions')->insert([
                'environment_variable_id' => $variable->id,
                'created_by' => $variable->updated_by,
                'version' => 1,
                'value' => $variable->value,
                'created_at' => $variable->created_at,
                'updated_at' => $variable->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_variable_versions');
        Schema::table('environment_variables', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'current_version', 'rotated_at', 'rotation_due_at']);
        });
    }
};
