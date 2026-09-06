<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environments', function (Blueprint $table): void {
            $table->string('runtime_type', 20)->default('php')->after('branch');
            $table->string('runtime_version', 20)->nullable()->after('runtime_type');
            $table->text('build_command')->nullable()->after('runtime_version');
            $table->text('start_command')->nullable()->after('build_command');
            $table->unsignedSmallInteger('container_port')->nullable()->after('start_command');
            $table->string('dockerfile_path', 255)->nullable()->after('container_port');
        });
    }

    public function down(): void
    {
        Schema::table('environments', fn (Blueprint $table) => $table->dropColumn([
            'runtime_type', 'runtime_version', 'build_command', 'start_command', 'container_port', 'dockerfile_path',
        ]));
    }
};
