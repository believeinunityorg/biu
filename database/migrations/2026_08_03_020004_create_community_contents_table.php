<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_contents')) {
            return;
        }

        Schema::create('community_contents', function (Blueprint $table) {
            $table->id();
            $table->string('parent_type', 64);
            $table->unsignedBigInteger('parent_id');
            $table->string('type', 32); // announcement|discussion
            $table->string('title');
            $table->text('body');
            $table->string('cover_image')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 100)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('comments_enabled')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('visibility_status', 32)->default('visible'); // visible|hidden|removed
            $table->timestamps();

            $table->index(['parent_type', 'parent_id', 'type']);
            $table->index(['visibility_status', 'published_at']);
            $table->index('author_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_contents');
    }
};
