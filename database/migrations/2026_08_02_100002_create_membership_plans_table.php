<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('membership_plans')) {
            return;
        }

        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('account_type', 64);
            $table->string('membership_name');
            $table->string('membership_type', 32);
            $table->decimal('membership_fee', 10, 2)->nullable();
            $table->string('billing_frequency', 32)->nullable();
            $table->string('join_method', 32);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['account_type', 'account_id']);
            $table->index(['account_type', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
