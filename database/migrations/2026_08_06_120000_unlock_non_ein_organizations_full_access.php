<?php

use App\Models\CareAlliance;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Non-EIN organizations should have the same module access as EIN organizations.
 * Previously, missing IRS tax_period forced compliance lock + organization_pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('organizations', 'has_ein')) {
            return;
        }

        Role::findOrCreate('organization', 'web');

        Organization::query()
            ->where('has_ein', false)
            ->where(function ($q) {
                $q->where('registration_status', 'pending')
                    ->orWhere('is_compliance_locked', true);
            })
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($organizations) {
                foreach ($organizations as $organization) {
                    /** @var Organization $organization */
                    $organization->registration_status = 'approved';
                    $organization->is_compliance_locked = false;
                    if ($organization->status === 'Inactive') {
                        $organization->status = 'Active';
                    }
                    $organization->tax_compliance_status = 'not_applicable';
                    $meta = is_array($organization->tax_compliance_meta) ? $organization->tax_compliance_meta : [];
                    $meta['status'] = 'not_applicable';
                    $meta['reason'] = 'organization_registered_without_ein';
                    $meta['unlocked_at'] = now()->toIso8601String();
                    $organization->tax_compliance_meta = $meta;
                    $organization->tax_compliance_checked_at = now();
                    $organization->save();

                    $user = $organization->user;
                    if (! $user instanceof User) {
                        continue;
                    }

                    $hasCareAlliance = CareAlliance::where('creator_user_id', $user->id)->exists();
                    $roles = ['organization'];
                    if ($hasCareAlliance) {
                        Role::findOrCreate('care_alliance', 'web');
                        $roles[] = 'care_alliance';
                    }

                    $user->syncRoles($roles);
                    $user->role = $hasCareAlliance ? 'care_alliance' : 'organization';
                    $user->save();
                }
            });
    }

    public function down(): void
    {
        // Irreversible data unlock — intentionally left empty.
    }
};
