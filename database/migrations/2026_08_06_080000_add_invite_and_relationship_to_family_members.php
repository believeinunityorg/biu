<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('family_members')) {
            return;
        }

        Schema::table('family_members', function (Blueprint $table) {
            if (! Schema::hasColumn('family_members', 'relationship_label')) {
                $table->string('relationship_label', 64)->nullable()->after('generation');
            }
            if (! Schema::hasColumn('family_members', 'invite_token')) {
                $table->string('invite_token', 64)->nullable()->unique()->after('claimed_at');
            }
            if (! Schema::hasColumn('family_members', 'invited_at')) {
                $table->timestamp('invited_at')->nullable()->after('invite_token');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('family_members')) {
            return;
        }

        Schema::table('family_members', function (Blueprint $table) {
            foreach (['relationship_label', 'invite_token', 'invited_at'] as $column) {
                if (Schema::hasColumn('family_members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
