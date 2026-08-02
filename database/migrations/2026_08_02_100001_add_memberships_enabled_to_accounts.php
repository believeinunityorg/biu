<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'memberships_enabled')) {
                $table->boolean('memberships_enabled')->default(false)->after('gift_card_terms_approved_at');
            }
        });

        Schema::table('care_alliances', function (Blueprint $table) {
            if (! Schema::hasColumn('care_alliances', 'memberships_enabled')) {
                $table->boolean('memberships_enabled')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (Schema::hasColumn('organizations', 'memberships_enabled')) {
                $table->dropColumn('memberships_enabled');
            }
        });

        Schema::table('care_alliances', function (Blueprint $table) {
            if (Schema::hasColumn('care_alliances', 'memberships_enabled')) {
                $table->dropColumn('memberships_enabled');
            }
        });
    }
};
