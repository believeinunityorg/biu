<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('family_members')) {
            Schema::create('family_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('full_name');
                $table->string('email')->nullable()->index();
                $table->string('photo')->nullable();
                $table->unsignedSmallInteger('birth_year')->nullable();
                $table->unsignedSmallInteger('death_year')->nullable();
                $table->unsignedBigInteger('father_id')->nullable()->index();
                $table->unsignedBigInteger('mother_id')->nullable()->index();
                $table->unsignedBigInteger('spouse_id')->nullable()->index();
                $table->unsignedTinyInteger('generation')->nullable()->index();
                $table->string('status', 32)->default('active')->index();
                $table->boolean('is_founding_grandfather')->default(false);
                $table->boolean('is_founding_grandmother')->default(false);
                $table->unsignedBigInteger('merged_into_id')->nullable()->index();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status']);
                $table->index(['organization_id', 'email']);
            });
        }

        if (! Schema::hasTable('family_branches')) {
            Schema::create('family_branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->string('name');
                $table->foreignId('head_member_id')->nullable()->constrained('family_members')->nullOnDelete();
                $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32)->default('active')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['organization_id', 'status']);
            });
        }

        if (Schema::hasTable('family_members')) {
            Schema::table('family_members', function (Blueprint $table) {
                if (! $this->foreignKeyExists('family_members', 'family_members_branch_id_foreign')) {
                    $table->foreign('branch_id')->references('id')->on('family_branches')->nullOnDelete();
                }
                if (! $this->foreignKeyExists('family_members', 'family_members_father_id_foreign')) {
                    $table->foreign('father_id')->references('id')->on('family_members')->nullOnDelete();
                }
                if (! $this->foreignKeyExists('family_members', 'family_members_mother_id_foreign')) {
                    $table->foreign('mother_id')->references('id')->on('family_members')->nullOnDelete();
                }
                if (! $this->foreignKeyExists('family_members', 'family_members_spouse_id_foreign')) {
                    $table->foreign('spouse_id')->references('id')->on('family_members')->nullOnDelete();
                }
                if (! $this->foreignKeyExists('family_members', 'family_members_merged_into_id_foreign')) {
                    $table->foreign('merged_into_id')->references('id')->on('family_members')->nullOnDelete();
                }
            });
        }

        if (! Schema::hasTable('family_founders')) {
            Schema::create('family_founders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->unique()->constrained('organizations')->cascadeOnDelete();
                $table->string('grandfather_name');
                $table->string('grandmother_name');
                $table->string('grandfather_photo')->nullable();
                $table->string('grandmother_photo')->nullable();
                $table->unsignedSmallInteger('grandfather_birth_year')->nullable();
                $table->unsignedSmallInteger('grandfather_death_year')->nullable();
                $table->unsignedSmallInteger('grandmother_birth_year')->nullable();
                $table->unsignedSmallInteger('grandmother_death_year')->nullable();
                $table->foreignId('grandfather_member_id')->nullable()->constrained('family_members')->nullOnDelete();
                $table->foreignId('grandmother_member_id')->nullable()->constrained('family_members')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('family_relationship_audits')) {
            Schema::create('family_relationship_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('family_member_id')->constrained('family_members')->cascadeOnDelete();
                $table->string('field', 64);
                $table->string('old_value')->nullable();
                $table->string('new_value')->nullable();
                $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['organization_id', 'family_member_id'], 'family_rel_audits_org_member_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_relationship_audits');
        Schema::dropIfExists('family_founders');

        if (Schema::hasTable('family_members')) {
            Schema::table('family_members', function (Blueprint $table) {
                foreach (['branch_id', 'father_id', 'mother_id', 'spouse_id', 'merged_into_id'] as $column) {
                    try {
                        $table->dropForeign([$column]);
                    } catch (\Throwable) {
                        // ignore missing FK
                    }
                }
            });
        }

        Schema::dropIfExists('family_branches');
        Schema::dropIfExists('family_members');
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $database = Schema::getConnection()->getDatabaseName();
        $result = Schema::getConnection()->selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $name, 'FOREIGN KEY']
        );

        return $result !== null;
    }
};
