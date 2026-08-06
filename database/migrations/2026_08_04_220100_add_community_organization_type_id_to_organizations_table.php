<?php

use App\Models\CommunityOrganizationType;
use App\Models\Organization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        if (! Schema::hasColumn('organizations', 'community_organization_type_id')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->foreignId('community_organization_type_id')
                    ->nullable()
                    ->after('ntee_code')
                    ->constrained('community_organization_types')
                    ->nullOnDelete();
            });
        }

        if (CommunityOrganizationType::query()->count() === 0) {
            (new \Database\Seeders\CommunityOrganizationTypesSeeder)->run();
        }

        if (Schema::hasColumn('organizations', 'community_organization_type')) {
            Organization::query()
                ->whereNotNull('community_organization_type')
                ->where('community_organization_type', '!=', '')
                ->orderBy('id')
                ->chunkById(200, function ($organizations) {
                    $slugMap = CommunityOrganizationType::query()
                        ->pluck('id', 'slug')
                        ->all();

                    foreach ($organizations as $organization) {
                        $slug = (string) $organization->getAttribute('community_organization_type');
                        $typeId = $slugMap[$slug] ?? null;
                        if ($typeId) {
                            $organization->forceFill([
                                'community_organization_type_id' => $typeId,
                            ])->saveQuietly();
                        }
                    }
                });

            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn('community_organization_type');
            });
        }

        // Keep community_organization_type_other; move after type_id if needed is optional.
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        if (! Schema::hasColumn('organizations', 'community_organization_type')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('community_organization_type', 64)->nullable()->after('ntee_code');
            });
        }

        if (Schema::hasColumn('organizations', 'community_organization_type_id')) {
            Organization::query()
                ->whereNotNull('community_organization_type_id')
                ->with('communityOrganizationType')
                ->orderBy('id')
                ->chunkById(200, function ($organizations) {
                    foreach ($organizations as $organization) {
                        $organization->forceFill([
                            'community_organization_type' => $organization->communityOrganizationType?->slug,
                        ])->saveQuietly();
                    }
                });

            Schema::table('organizations', function (Blueprint $table) {
                $table->dropConstrainedForeignId('community_organization_type_id');
            });
        }
    }
};
