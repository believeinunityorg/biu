<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('family_reunion_profiles')) {
            Schema::table('family_reunion_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('family_reunion_profiles', 'family_history')) {
                    $table->text('family_history')->nullable()->after('family_motto');
                }
                if (! Schema::hasColumn('family_reunion_profiles', 'reunion_established')) {
                    $table->date('reunion_established')->nullable()->after('family_history');
                }
            });
        }

        if (Schema::hasTable('family_branches')) {
            Schema::table('family_branches', function (Blueprint $table) {
                if (! Schema::hasColumn('family_branches', 'admin_name')) {
                    $table->string('admin_name')->nullable()->after('admin_user_id');
                }
                if (! Schema::hasColumn('family_branches', 'admin_email')) {
                    $table->string('admin_email')->nullable()->after('admin_name');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('family_reunion_profiles')) {
            Schema::table('family_reunion_profiles', function (Blueprint $table) {
                foreach (['family_history', 'reunion_established'] as $column) {
                    if (Schema::hasColumn('family_reunion_profiles', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('family_branches')) {
            Schema::table('family_branches', function (Blueprint $table) {
                foreach (['admin_name', 'admin_email'] as $column) {
                    if (Schema::hasColumn('family_branches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
