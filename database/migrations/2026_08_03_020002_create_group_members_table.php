<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('group_members')) {
            return;
        }

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // admin|moderator|member
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('posting_suspended_until')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
            $table->index(['group_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};
