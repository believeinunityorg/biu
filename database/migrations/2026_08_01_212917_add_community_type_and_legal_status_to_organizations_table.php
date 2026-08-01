<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (! Schema::hasColumn('organizations', 'community_organization_type')) {
                $table->string('community_organization_type', 64)->nullable()->after('ntee_code');
            }
            if (! Schema::hasColumn('organizations', 'community_organization_type_other')) {
                $table->string('community_organization_type_other', 255)->nullable()->after('community_organization_type');
            }
            if (! Schema::hasColumn('organizations', 'legal_entity_status')) {
                $table->string('legal_entity_status', 64)->nullable()->after('community_organization_type_other');
            }
            if (! Schema::hasColumn('organizations', 'legal_entity_status_other')) {
                $table->string('legal_entity_status_other', 255)->nullable()->after('legal_entity_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $columns = [
                'community_organization_type',
                'community_organization_type_other',
                'legal_entity_status',
                'legal_entity_status_other',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
