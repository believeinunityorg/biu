<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('group_invites')) {
            return;
        }

        Schema::create('group_invites', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitee_email')->nullable();
            $table->string('status', 32)->default('pending'); // pending|accepted|declined|expired|revoked
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_invites');
    }
};
