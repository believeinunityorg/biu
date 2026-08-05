<?php

namespace Database\Factories;

use App\Enums\OrganizationAnnouncementStatus;
use App\Models\Organization;
use App\Models\OrganizationAnnouncement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationAnnouncement>
 */
class OrganizationAnnouncementFactory extends Factory
{
    protected $model = OrganizationAnnouncement::class;

    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'organization_id' => Organization::query()->value('id') ?? 1,
            'title' => $title,
            'slug' => OrganizationAnnouncement::uniqueSlugFromTitle($title),
            'message' => '<p>'.fake()->paragraphs(2, true).'</p>',
            'category' => fake()->randomElement(config('communication_hub.announcement_categories', ['Update'])),
            'cover_image' => null,
            'attachments' => null,
            'published_at' => now()->subDays(fake()->numberBetween(0, 14)),
            'scheduled_at' => null,
            'expires_at' => null,
            'is_pinned' => false,
            'allow_comments' => true,
            'status' => OrganizationAnnouncementStatus::Published,
            'created_by' => User::factory(),
            'updated_by' => null,
            'published_by' => null,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => OrganizationAnnouncementStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => OrganizationAnnouncementStatus::Scheduled,
            'published_at' => null,
            'scheduled_at' => now()->addDays(2),
        ]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }
}
