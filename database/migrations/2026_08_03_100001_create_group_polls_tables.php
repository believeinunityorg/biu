<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('group_polls')) {
            Schema::create('group_polls', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
                $table->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('question');
                $table->boolean('allow_multiple')->default(false);
                $table->timestamp('closes_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['group_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('group_poll_options')) {
            Schema::create('group_poll_options', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_poll_id')->constrained('group_polls')->cascadeOnDelete();
                $table->string('label');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('group_poll_votes')) {
            Schema::create('group_poll_votes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('group_poll_id')->constrained('group_polls')->cascadeOnDelete();
                $table->foreignId('group_poll_option_id')->constrained('group_poll_options')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['group_poll_id', 'user_id', 'group_poll_option_id'], 'group_poll_vote_unique');
                $table->index(['group_poll_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('group_poll_votes');
        Schema::dropIfExists('group_poll_options');
        Schema::dropIfExists('group_polls');
    }
};
