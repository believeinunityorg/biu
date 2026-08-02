<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('community_reactions')) {
            Schema::create('community_reactions', function (Blueprint $table) {
                $table->id();
                $table->string('reactable_type', 64);
                $table->unsignedBigInteger('reactable_id');
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('type', 32)->default('like');
                $table->timestamps();

                $table->unique(['reactable_type', 'reactable_id', 'user_id', 'type'], 'community_reactions_unique');
                $table->index(['reactable_type', 'reactable_id']);
            });
        }

        if (! Schema::hasTable('community_follows')) {
            Schema::create('community_follows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('community_content_id')->constrained('community_contents')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('is_muted')->default(false);
                $table->timestamps();

                $table->unique(['community_content_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('community_follows');
        Schema::dropIfExists('community_reactions');
    }
};
