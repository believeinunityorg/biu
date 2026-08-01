<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'has_ein')) {
                $table->boolean('has_ein')->default(true)->after('ein');
            }
            if (! Schema::hasColumn('organizations', 'has_members')) {
                $table->boolean('has_members')->nullable()->after('has_ein');
            }
            if (! Schema::hasColumn('organizations', 'memberships_enabled')) {
                $table->boolean('memberships_enabled')->default(false)->after('has_members');
            }
            if (! Schema::hasColumn('organizations', 'membership_type')) {
                $table->string('membership_type', 16)->nullable()->after('memberships_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach (['has_ein', 'has_members', 'memberships_enabled', 'membership_type'] as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
