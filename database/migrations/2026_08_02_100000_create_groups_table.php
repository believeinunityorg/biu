<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('parent_type', 64);
            $table->unsignedBigInteger('parent_id');
            $table->foreignId('creator_user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('memberships_enabled')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['parent_type', 'parent_id']);
            $table->index('creator_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
