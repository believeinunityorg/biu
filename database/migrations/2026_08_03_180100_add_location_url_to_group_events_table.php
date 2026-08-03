<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('group_events') && ! Schema::hasColumn('group_events', 'location_url')) {
            Schema::table('group_events', function (Blueprint $table) {
                $table->string('location_url')->nullable()->after('location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('group_events') && Schema::hasColumn('group_events', 'location_url')) {
            Schema::table('group_events', function (Blueprint $table) {
                $table->dropColumn('location_url');
            });
        }
    }
};
