<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('family_reunion_profiles')) {
            return;
        }

        Schema::create('family_reunion_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
            $table->string('family_name')->nullable();
            $table->string('family_motto')->nullable();
            $table->string('reunion_frequency', 64)->nullable();
            $table->string('reunion_location')->nullable();
            $table->string('tree_visibility', 64)->default('family_members_only');
            $table->boolean('allow_members_add_children')->default(true);
            $table->boolean('allow_members_invite_relatives')->default(true);
            $table->boolean('require_member_approval')->default(false);
            $table->boolean('allow_branch_administrators')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_reunion_profiles');
    }
};
