<?php

namespace Database\Seeders;

use App\Enums\OrganizationAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\User;
use App\Services\CommunicationHub\OrganizationDiscussionService;
use Illuminate\Database\Seeder;

class CommunicationHubSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('registration_status', 'approved')->first()
            ?? Organization::query()->first();

        if (! $organization) {
            $this->command?->warn('CommunicationHubSeeder: no organization found, skipping.');

            return;
        }

        $author = $organization->user ?? User::query()->first();
        if (! $author) {
            $this->command?->warn('CommunicationHubSeeder: no user found, skipping.');

            return;
        }

        app(OrganizationDiscussionService::class)->ensureDefaultCategories($organization);

        $categories = OrganizationDiscussionCategory::query()
            ->where('organization_id', $organization->id)
            ->get();

        $demoAnnouncements = [
            [
                'title' => 'Save the Date: Annual Community Gathering',
                'category' => 'Event',
                'message' => '<p>Join us for our annual community gathering featuring speakers, workshops, and networking.</p><p>Mark your calendars and invite a friend!</p>',
                'is_pinned' => true,
            ],
            [
                'title' => 'New Resource Library Now Available',
                'category' => 'Resource',
                'message' => '<p>We have published a curated library of guides, toolkits, and templates for members.</p>',
                'is_pinned' => false,
            ],
            [
                'title' => 'Platform Update: Communication Hub Launch',
                'category' => 'Update',
                'message' => '<p>Announcements and discussions are now organized in your Communication Hub.</p>',
                'is_pinned' => false,
            ],
        ];

        foreach ($demoAnnouncements as $demo) {
            $exists = OrganizationAnnouncement::query()
                ->where('organization_id', $organization->id)
                ->where('title', $demo['title'])
                ->exists();

            if ($exists) {
                continue;
            }

            OrganizationAnnouncement::create([
                'organization_id' => $organization->id,
                'title' => $demo['title'],
                'slug' => OrganizationAnnouncement::uniqueSlugFromTitle($demo['title']),
                'message' => $demo['message'],
                'category' => $demo['category'],
                'published_at' => now()->subDays(rand(1, 10)),
                'is_pinned' => $demo['is_pinned'],
                'allow_comments' => true,
                'status' => OrganizationAnnouncementStatus::Published,
                'created_by' => $author->id,
                'published_by' => $author->id,
            ]);
        }

        $demoDiscussions = [
            ['title' => 'Ideas for next volunteer day', 'body' => 'What causes or projects should we prioritize for the next volunteer day?'],
            ['title' => 'Welcome new members — introduce yourself', 'body' => 'Say hello and share what brought you to this community.'],
            ['title' => 'Best practices for event outreach', 'body' => 'Share what has worked well when promoting events to supporters.'],
        ];

        foreach ($demoDiscussions as $i => $demo) {
            $exists = OrganizationDiscussion::query()
                ->where('organization_id', $organization->id)
                ->where('title', $demo['title'])
                ->exists();

            if ($exists) {
                continue;
            }

            $category = $categories->get($i % max(1, $categories->count()));

            OrganizationDiscussion::create([
                'organization_id' => $organization->id,
                'category_id' => $category?->id,
                'title' => $demo['title'],
                'slug' => OrganizationDiscussion::uniqueSlugFromTitle($demo['title']),
                'body' => $demo['body'],
                'created_by' => $author->id,
                'is_pinned' => $i === 0,
                'replies_count' => 0,
                'views_count' => rand(5, 120),
                'reactions_count' => 0,
                'last_activity_at' => now()->subHours(rand(1, 48)),
                'approved_at' => now(),
            ]);
        }

        $this->command?->info("Communication Hub demo data seeded for organization #{$organization->id} ({$organization->name}).");
    }
}
