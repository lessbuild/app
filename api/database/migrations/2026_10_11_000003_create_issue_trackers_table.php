<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the table of ticket trackers (GitHub Issues, Linear, Jira) a project files Monitoring issues into, and
     * link issues to the ticket filed for them.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('issue_trackers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->text('settings');
            $table->timestamps();
        });
        Schema::table('issues', function (Blueprint $table): void {
            $table->string('ticket_key', 100)->nullable();
            $table->string('ticket_url', 500)->nullable();
        });
    }

    /**
     * Drop the trackers and the ticket links.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table('issues', function (Blueprint $table): void {
            $table->dropColumn(['ticket_key', 'ticket_url']);
        });
        Schema::dropIfExists('issue_trackers');
    }
};
