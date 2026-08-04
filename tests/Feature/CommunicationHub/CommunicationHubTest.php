<?php

namespace Tests\Feature\CommunicationHub;

use App\Enums\OrganizationAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationDiscussion;
use App\Models\User;
use App\Services\CommunicationHub\CommunicationHubPermissionService;
use App\Services\CommunicationHub\OrganizationAnnouncementService;
use App\Services\CommunicationHub\OrganizationDiscussionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationHubTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgUser(): array
    {
        $user = User::factory()->create([
            'role' => 'organization',
            'email_verified_at' => now(),
        ]);

        $org = Organization::query()->create([
            'user_id' => $user->id,
            'name' => 'Test Org Communication Hub',
            'registration_status' => 'approved',
            'ein' => '12-3456789',
        ]);

        if (method_exists($user, 'assignRole')) {
            try {
                $user->assignRole('organization');
            } catch (\Throwable) {
                // Role may not exist in minimal test DB; continue.
            }
        }

        return [$user, $org];
    }

    public function test_announcement_is_scoped_to_organization(): void
    {
        [, $orgA] = $this->makeOrgUser();
        [, $orgB] = $this->makeOrgUser();
        $author = User::factory()->create();

        $announcement = OrganizationAnnouncement::factory()->create([
            'organization_id' => $orgA->id,
            'created_by' => $author->id,
            'published_by' => $author->id,
            'status' => OrganizationAnnouncementStatus::Published,
        ]);

        $this->assertSame($orgA->id, $announcement->organization_id);
        $this->assertTrue(
            OrganizationAnnouncement::query()->forOrganization($orgA->id)->whereKey($announcement->id)->exists()
        );
        $this->assertFalse(
            OrganizationAnnouncement::query()->forOrganization($orgB->id)->whereKey($announcement->id)->exists()
        );
    }

    public function test_manager_can_create_announcement(): void
    {
        [$user, $org] = $this->makeOrgUser();
        $service = app(OrganizationAnnouncementService::class);

        $announcement = $service->create($user, $org, [
            'title' => 'Hub Launch',
            'message' => '<p>Welcome to the Communication Hub.</p>',
            'category' => 'Update',
            'publish_now' => true,
            'allow_comments' => true,
        ]);

        $this->assertDatabaseHas('organization_announcements', [
            'id' => $announcement->id,
            'organization_id' => $org->id,
            'title' => 'Hub Launch',
            'status' => 'published',
        ]);
    }

    public function test_follower_cannot_create_announcement(): void
    {
        [, $org] = $this->makeOrgUser();
        $follower = User::factory()->create(['role' => 'user']);
        $service = app(OrganizationAnnouncementService::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->create($follower, $org, [
            'title' => 'Unauthorized',
            'message' => 'Nope',
            'publish_now' => true,
        ]);
    }

    public function test_discussion_create_respects_settings(): void
    {
        [$owner, $org] = $this->makeOrgUser();
        $org->update([
            'communication_settings' => array_merge(
                config('communication_hub.default_settings'),
                ['allow_followers_to_post' => false, 'allow_members_to_post' => false]
            ),
        ]);

        $permissions = app(CommunicationHubPermissionService::class);
        $outsider = User::factory()->create(['role' => 'user']);

        $this->assertTrue($permissions->canCreateDiscussion($owner, $org->fresh()));
        $this->assertFalse($permissions->canCreateDiscussion($outsider, $org->fresh()));
    }

    public function test_discussion_soft_delete_only(): void
    {
        [$user, $org] = $this->makeOrgUser();
        $service = app(OrganizationDiscussionService::class);

        $discussion = $service->create($user, $org, [
            'title' => 'Soft delete me',
            'body' => 'This should soft delete.',
        ]);

        $service->softDelete($discussion, $user);

        $this->assertSoftDeleted('organization_discussions', ['id' => $discussion->id]);
        $this->assertDatabaseHas('organization_discussions', ['id' => $discussion->id]);
    }

    public function test_hub_index_requires_authentication(): void
    {
        $this->get('/organization/communication-hub')->assertRedirect();
    }

    public function test_scheduled_announcement_promotes_when_due(): void
    {
        [, $org] = $this->makeOrgUser();
        $author = User::factory()->create();

        $announcement = OrganizationAnnouncement::factory()->create([
            'organization_id' => $org->id,
            'created_by' => $author->id,
            'status' => OrganizationAnnouncementStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
            'published_at' => null,
        ]);

        OrganizationAnnouncement::promoteScheduledForOrganization($org->id);

        $this->assertSame(
            OrganizationAnnouncementStatus::Published,
            $announcement->fresh()->status
        );
    }
}
