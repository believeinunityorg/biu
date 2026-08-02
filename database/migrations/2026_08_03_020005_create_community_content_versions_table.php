<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('community_content_versions')) {
            return;
        }

        Schema::create('community_content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('community_content_id')->constrained('community_contents')->cascadeOnDelete();
            $table->foreignId('edited_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('community_content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_content_versions');
    }
};
