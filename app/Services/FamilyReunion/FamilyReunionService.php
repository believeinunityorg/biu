<?php

namespace App\Services\FamilyReunion;

use App\Enums\FamilyBranchStatus;
use App\Enums\FamilyMemberStatus;
use App\Models\FamilyBranch;
use App\Models\FamilyFounder;
use App\Models\FamilyMember;
use App\Models\FamilyRelationshipAudit;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FamilyReunionService
{
    public function resolveOrganization(User $user): ?Organization
    {
        return Organization::forAuthUser($user);
    }

    public function assertFamilyReunion(Organization $organization): void
    {
        if (! $organization->isFamilyReunion()) {
            abort(403, 'This organization is not a Family Reunion.');
        }
    }

    /**
     * @param  array{
     *     grandfather_name: string,
     *     grandmother_name: string,
     *     grandfather_birth_year?: int|null,
     *     grandfather_death_year?: int|null,
     *     grandmother_birth_year?: int|null,
     *     grandmother_death_year?: int|null,
     * }  $data
     */
    public function saveFoundingCouple(
        Organization $organization,
        array $data,
        ?UploadedFile $grandfatherPhoto = null,
        ?UploadedFile $grandmotherPhoto = null,
        ?User $actor = null,
    ): FamilyFounder {
        return DB::transaction(function () use ($organization, $data, $grandfatherPhoto, $grandmotherPhoto, $actor) {
            $founder = FamilyFounder::query()->firstOrNew(['organization_id' => $organization->id]);

            if ($grandfatherPhoto) {
                $founder->grandfather_photo = $this->storePhoto($grandfatherPhoto, $organization->id, 'grandfather');
            }
            if ($grandmotherPhoto) {
                $founder->grandmother_photo = $this->storePhoto($grandmotherPhoto, $organization->id, 'grandmother');
            }

            $founder->fill([
                'grandfather_name' => $data['grandfather_name'],
                'grandmother_name' => $data['grandmother_name'],
                'grandfather_birth_year' => $data['grandfather_birth_year'] ?? null,
                'grandfather_death_year' => $data['grandfather_death_year'] ?? null,
                'grandmother_birth_year' => $data['grandmother_birth_year'] ?? null,
                'grandmother_death_year' => $data['grandmother_death_year'] ?? null,
            ]);
            $founder->save();

            $grandfather = $this->upsertFounderMember(
                $organization,
                $founder->grandfather_member_id,
                [
                    'full_name' => $data['grandfather_name'],
                    'photo' => $founder->grandfather_photo,
                    'birth_year' => $data['grandfather_birth_year'] ?? null,
                    'death_year' => $data['grandfather_death_year'] ?? null,
                    'is_founding_grandfather' => true,
                    'is_founding_grandmother' => false,
                    'generation' => 0,
                    'status' => FamilyMemberStatus::Active,
                ],
                $actor,
            );

            $grandmother = $this->upsertFounderMember(
                $organization,
                $founder->grandmother_member_id,
                [
                    'full_name' => $data['grandmother_name'],
                    'photo' => $founder->grandmother_photo,
                    'birth_year' => $data['grandmother_birth_year'] ?? null,
                    'death_year' => $data['grandmother_death_year'] ?? null,
                    'is_founding_grandfather' => false,
                    'is_founding_grandmother' => true,
                    'generation' => 0,
                    'status' => FamilyMemberStatus::Active,
                ],
                $actor,
            );

            $grandfather->spouse_id = $grandmother->id;
            $grandmother->spouse_id = $grandfather->id;
            $grandfather->save();
            $grandmother->save();

            $founder->grandfather_member_id = $grandfather->id;
            $founder->grandmother_member_id = $grandmother->id;
            $founder->save();

            return $founder->fresh(['grandfatherMember', 'grandmotherMember']);
        });
    }

    /**
     * @param  array{
     *     name: string,
     *     head_member_full_name?: string|null,
     *     head_member_id?: int|null,
     *     admin_name?: string|null,
     *     admin_email?: string|null,
     * }  $data
     */
    public function createBranch(Organization $organization, array $data, ?User $actor = null): FamilyBranch
    {
        return DB::transaction(function () use ($organization, $data, $actor) {
            $founder = $organization->familyFounder;
            $grandfatherId = $founder?->grandfather_member_id;
            $grandmotherId = $founder?->grandmother_member_id;

            $headMemberId = $data['head_member_id'] ?? null;
            $adminName = isset($data['admin_name']) ? trim((string) $data['admin_name']) : null;
            $adminEmail = isset($data['admin_email']) ? strtolower(trim((string) $data['admin_email'])) : null;
            $adminUserId = null;

            if ($adminEmail) {
                $adminUserId = User::query()->whereRaw('LOWER(email) = ?', [$adminEmail])->value('id');
            }

            if (! $headMemberId) {
                $head = FamilyMember::query()->create([
                    'organization_id' => $organization->id,
                    'full_name' => $data['head_member_full_name'] ?: ($adminName ?: $data['name']),
                    'email' => $adminEmail,
                    'user_id' => $adminUserId,
                    'father_id' => $grandfatherId,
                    'mother_id' => $grandmotherId,
                    'generation' => 1,
                    'status' => $adminUserId ? FamilyMemberStatus::Active : ($adminEmail ? FamilyMemberStatus::Unclaimed : FamilyMemberStatus::Active),
                    'claimed_at' => $adminUserId ? now() : null,
                ]);
                $headMemberId = $head->id;

                $this->audit($organization, $head, 'created', null, 'branch_head', $actor);
            }

            $maxSort = (int) FamilyBranch::query()
                ->forOrganization($organization->id)
                ->max('sort_order');

            $branch = FamilyBranch::query()->create([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'head_member_id' => $headMemberId,
                'admin_user_id' => $adminUserId,
                'admin_name' => $adminName ?: null,
                'admin_email' => $adminEmail ?: null,
                'status' => FamilyBranchStatus::Active,
                'sort_order' => $maxSort + 1,
            ]);

            FamilyMember::query()
                ->where('id', $headMemberId)
                ->where('organization_id', $organization->id)
                ->update(['branch_id' => $branch->id]);

            return $branch->fresh(['headMember', 'adminUser']);
        });
    }

    /**
     * @param  array{
     *     full_name: string,
     *     email?: string|null,
     *     branch_id?: int|null,
     *     father_id?: int|null,
     *     mother_id?: int|null,
     *     spouse_id?: int|null,
     *     generation?: int|null,
     * }  $data
     */
    public function addChild(Organization $organization, array $data, ?User $actor = null): FamilyMember
    {
        $email = isset($data['email']) ? strtolower(trim((string) $data['email'])) : null;
        $status = $email ? FamilyMemberStatus::Unclaimed : FamilyMemberStatus::Active;

        $existingUser = $email
            ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first()
            : null;

        if ($existingUser) {
            $status = FamilyMemberStatus::Active;
        }

        $generation = $data['generation'] ?? $this->inferGeneration($data['father_id'] ?? null, $data['mother_id'] ?? null);

        $member = FamilyMember::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $existingUser?->id,
            'branch_id' => $data['branch_id'] ?? null,
            'full_name' => $data['full_name'],
            'email' => $email,
            'father_id' => $data['father_id'] ?? null,
            'mother_id' => $data['mother_id'] ?? null,
            'spouse_id' => $data['spouse_id'] ?? null,
            'generation' => $generation,
            'status' => $status,
            'claimed_at' => $existingUser ? now() : null,
        ]);

        $this->audit($organization, $member, 'created', null, 'child', $actor);

        return $member->fresh(['branch', 'father', 'mother']);
    }

    /**
     * Profile setup when joining: branch + father + mother.
     *
     * @param  array{
     *     branch_id: int,
     *     father_id?: int|null,
     *     mother_id?: int|null,
     *     full_name?: string|null,
     * }  $data
     */
    public function setupMemberProfile(Organization $organization, User $user, array $data): FamilyMember
    {
        return DB::transaction(function () use ($organization, $user, $data) {
            $member = FamilyMember::query()
                ->forOrganization($organization->id)
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereRaw('LOWER(email) = ?', [strtolower($user->email)]);
                })
                ->visible()
                ->first();

            if (! $member) {
                $member = FamilyMember::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'full_name' => $data['full_name'] ?: $user->name,
                    'email' => strtolower($user->email),
                    'status' => FamilyMemberStatus::Active,
                    'claimed_at' => now(),
                ]);
            }

            $updates = [
                'branch_id' => $data['branch_id'],
                'father_id' => $data['father_id'] ?? null,
                'mother_id' => $data['mother_id'] ?? null,
                'user_id' => $user->id,
                'status' => FamilyMemberStatus::Active,
                'claimed_at' => $member->claimed_at ?? now(),
                'generation' => $this->inferGeneration($data['father_id'] ?? null, $data['mother_id'] ?? null),
            ];

            foreach (['branch_id', 'father_id', 'mother_id'] as $field) {
                $old = $member->{$field};
                $new = $updates[$field];
                if ((string) $old !== (string) $new) {
                    $this->audit($organization, $member, $field, $old, $new, $user);
                }
            }

            $member->fill($updates)->save();

            return $member->fresh(['branch', 'father', 'mother']);
        });
    }

    public function claimPendingForUser(User $user): array
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            return [];
        }

        $members = FamilyMember::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', FamilyMemberStatus::Unclaimed->value)
            ->whereNull('user_id')
            ->get();

        $claimed = [];
        foreach ($members as $member) {
            $member->user_id = $user->id;
            $member->status = FamilyMemberStatus::Active;
            $member->claimed_at = now();
            if (! $member->full_name) {
                $member->full_name = $user->name;
            }
            $member->save();
            $claimed[] = $member->id;
        }

        return $claimed;
    }

    public function mergeMembers(Organization $organization, FamilyMember $keep, FamilyMember $duplicate, ?User $actor = null): FamilyMember
    {
        return DB::transaction(function () use ($organization, $keep, $duplicate, $actor) {
            if ($keep->organization_id !== $organization->id || $duplicate->organization_id !== $organization->id) {
                abort(422, 'Members must belong to the same organization.');
            }

            FamilyMember::query()
                ->forOrganization($organization->id)
                ->where('father_id', $duplicate->id)
                ->update(['father_id' => $keep->id]);

            FamilyMember::query()
                ->forOrganization($organization->id)
                ->where('mother_id', $duplicate->id)
                ->update(['mother_id' => $keep->id]);

            FamilyMember::query()
                ->forOrganization($organization->id)
                ->where('spouse_id', $duplicate->id)
                ->update(['spouse_id' => $keep->id]);

            FamilyBranch::query()
                ->forOrganization($organization->id)
                ->where('head_member_id', $duplicate->id)
                ->update(['head_member_id' => $keep->id]);

            $duplicate->merged_into_id = $keep->id;
            $duplicate->status = FamilyMemberStatus::Merged;
            $duplicate->save();

            $this->audit($organization, $duplicate, 'merged_into', null, (string) $keep->id, $actor);

            return $keep->fresh();
        });
    }

    /**
     * @return array{founders: mixed, branches: array, stats: array, tree: array}
     */
    public function overviewPayload(Organization $organization): array
    {
        $founder = $organization->familyFounder()->with(['grandfatherMember', 'grandmotherMember'])->first();

        $branches = FamilyBranch::query()
            ->forOrganization($organization->id)
            ->active()
            ->with(['headMember'])
            ->withCount(['members' => fn ($q) => $q->visible()])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (FamilyBranch $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'members_count' => $b->members_count,
                'head_member' => $b->headMember ? [
                    'id' => $b->headMember->id,
                    'full_name' => $b->headMember->full_name,
                    'photo_url' => $b->headMember->photo_url,
                ] : null,
            ]);

        $membersQuery = FamilyMember::query()->forOrganization($organization->id)->visible();
        $totalMembers = (clone $membersQuery)->count();
        $connected = (clone $membersQuery)->whereNotNull('user_id')->count();
        $generations = (int) ((clone $membersQuery)->max('generation') ?? 0) + ($founder ? 1 : 0);

        return [
            'founders' => $founder ? [
                'grandfather_name' => $founder->grandfather_name,
                'grandmother_name' => $founder->grandmother_name,
                'grandfather_photo_url' => $founder->grandfather_photo_url,
                'grandmother_photo_url' => $founder->grandmother_photo_url,
                'grandfather_birth_year' => $founder->grandfather_birth_year,
                'grandfather_death_year' => $founder->grandfather_death_year,
                'grandmother_birth_year' => $founder->grandmother_birth_year,
                'grandmother_death_year' => $founder->grandmother_death_year,
                'grandfather_member_id' => $founder->grandfather_member_id,
                'grandmother_member_id' => $founder->grandmother_member_id,
            ] : null,
            'branches' => $branches,
            'stats' => [
                'total_members' => $totalMembers,
                'family_branches' => $branches->count(),
                'generations' => max($generations, $founder ? 1 : 0),
                'connected_members' => $connected,
                'active_since' => $organization->created_at?->format('M Y'),
            ],
            'tree' => $this->buildTree($organization),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTree(Organization $organization, ?int $branchId = null): array
    {
        $founder = $organization->familyFounder;
        $members = FamilyMember::query()
            ->forOrganization($organization->id)
            ->visible()
            ->when($branchId, fn ($q) => $q->where(function ($inner) use ($branchId, $founder) {
                $inner->where('branch_id', $branchId)
                    ->orWhere('is_founding_grandfather', true)
                    ->orWhere('is_founding_grandmother', true);
                if ($founder) {
                    $inner->orWhereIn('id', array_filter([
                        $founder->grandfather_member_id,
                        $founder->grandmother_member_id,
                    ]));
                }
            }))
            ->with(['branch:id,name'])
            ->get()
            ->keyBy('id');

        $branches = FamilyBranch::query()
            ->forOrganization($organization->id)
            ->active()
            ->when($branchId, fn ($q) => $q->where('id', $branchId))
            ->orderBy('sort_order')
            ->get();

        $mapMember = fn (FamilyMember $m) => [
            'id' => $m->id,
            'full_name' => $m->full_name,
            'photo_url' => $m->photo_url,
            'birth_year' => $m->birth_year,
            'death_year' => $m->death_year,
            'generation' => $m->generation,
            'status' => $m->status?->value ?? $m->status,
            'branch' => $m->branch?->name,
            'branch_id' => $m->branch_id,
            'father_id' => $m->father_id,
            'mother_id' => $m->mother_id,
            'spouse_id' => $m->spouse_id,
            'is_claimed' => $m->is_claimed,
            'children_ids' => $members
                ->filter(fn (FamilyMember $c) => $c->father_id === $m->id || $c->mother_id === $m->id)
                ->pluck('id')
                ->values()
                ->all(),
        ];

        return [
            'founders' => $founder ? [
                'grandfather' => $founder->grandfather_member_id && $members->has($founder->grandfather_member_id)
                    ? $mapMember($members[$founder->grandfather_member_id])
                    : null,
                'grandmother' => $founder->grandmother_member_id && $members->has($founder->grandmother_member_id)
                    ? $mapMember($members[$founder->grandmother_member_id])
                    : null,
            ] : null,
            'branches' => $branches->map(function (FamilyBranch $branch) use ($members, $mapMember) {
                $head = $branch->head_member_id && $members->has($branch->head_member_id)
                    ? $mapMember($members[$branch->head_member_id])
                    : null;

                $branchMembers = $members
                    ->filter(fn (FamilyMember $m) => $m->branch_id === $branch->id)
                    ->map($mapMember)
                    ->values();

                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                    'head' => $head,
                    'members' => $branchMembers,
                    'members_count' => $branchMembers->count(),
                ];
            })->values()->all(),
            'members' => $members->map($mapMember)->values()->all(),
        ];
    }

    public function updateRelationships(
        Organization $organization,
        FamilyMember $member,
        array $data,
        ?User $actor = null,
    ): FamilyMember {
        foreach (['father_id', 'mother_id', 'spouse_id', 'branch_id'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $old = $member->{$field};
            $new = $data[$field];
            if ((string) $old !== (string) $new) {
                $this->audit($organization, $member, $field, $old, $new, $actor);
                $member->{$field} = $new;
            }
        }

        if (isset($data['generation'])) {
            $member->generation = $data['generation'];
        } else {
            $member->generation = $this->inferGeneration($member->father_id, $member->mother_id);
        }

        $member->save();

        return $member->fresh(['father', 'mother', 'spouse', 'branch']);
    }

    private function upsertFounderMember(
        Organization $organization,
        ?int $memberId,
        array $attributes,
        ?User $actor,
    ): FamilyMember {
        $member = $memberId
            ? FamilyMember::query()->forOrganization($organization->id)->find($memberId)
            : null;

        if (! $member) {
            $member = new FamilyMember(['organization_id' => $organization->id]);
        }

        $member->fill($attributes);
        $member->save();

        return $member;
    }

    private function inferGeneration(?int $fatherId, ?int $motherId): int
    {
        $parentGen = null;
        if ($fatherId) {
            $parentGen = FamilyMember::query()->where('id', $fatherId)->value('generation');
        }
        if ($motherId) {
            $motherGen = FamilyMember::query()->where('id', $motherId)->value('generation');
            $parentGen = $parentGen === null ? $motherGen : max((int) $parentGen, (int) $motherGen);
        }

        return $parentGen === null ? 1 : ((int) $parentGen + 1);
    }

    private function storePhoto(UploadedFile $file, int $organizationId, string $prefix): string
    {
        return $file->store("family-reunion/{$organizationId}/{$prefix}", 'public');
    }

    private function audit(
        Organization $organization,
        FamilyMember $member,
        string $field,
        mixed $old,
        mixed $new,
        ?User $actor,
    ): void {
        FamilyRelationshipAudit::query()->create([
            'organization_id' => $organization->id,
            'family_member_id' => $member->id,
            'field' => $field,
            'old_value' => $old === null ? null : (string) $old,
            'new_value' => $new === null ? null : (string) $new,
            'changed_by_user_id' => $actor?->id,
        ]);
    }
}
