<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('content_reports')) {
            Schema::create('content_reports', function (Blueprint $table) {
                $table->id();
                $table->string('reportable_type', 64);
                $table->unsignedBigInteger('reportable_id');
                $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
                $table->string('reason', 64);
                $table->text('notes')->nullable();
                $table->string('status', 32)->default('open'); // open|reviewed|actioned|dismissed
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->index(['reportable_type', 'reportable_id']);
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('moderation_actions')) {
            Schema::create('moderation_actions', function (Blueprint $table) {
                $table->id();
                $table->string('target_type', 64);
                $table->unsignedBigInteger('target_id');
                $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
                $table->string('action', 64);
                $table->text('reason')->nullable();
                $table->json('original_snapshot')->nullable();
                $table->string('status_before', 64)->nullable();
                $table->string('status_after', 64)->nullable();
                $table->timestamps();

                $table->index(['target_type', 'target_id']);
                $table->index(['actor_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
        Schema::dropIfExists('content_reports');
    }
};
