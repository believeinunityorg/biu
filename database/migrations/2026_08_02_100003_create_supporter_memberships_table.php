<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supporter_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->string('account_type', 64);
            $table->string('status', 32)->default('pending');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['account_type', 'account_id', 'status']);
            $table->index(['supporter_id', 'account_type', 'account_id']);
            $table->index('membership_plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supporter_memberships');
    }
};
