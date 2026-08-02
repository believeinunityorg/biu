<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_replies')) {
            return;
        }

        Schema::create('community_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_content_id')->constrained('community_contents')->cascadeOnDelete();
            $table->foreignId('parent_reply_id')->nullable()->constrained('community_replies')->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('visibility_status', 32)->default('visible');
            $table->timestamps();

            $table->index(['community_content_id', 'visibility_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_replies');
    }
};
