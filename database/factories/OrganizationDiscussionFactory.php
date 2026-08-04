<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationDiscussion;
use App\Models\OrganizationDiscussionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationDiscussion>
 */
class OrganizationDiscussionFactory extends Factory
{
    protected $model = OrganizationDiscussion::class;

    public function definition(): array
    {
        $title = fake()->sentence(8);

        return [
            'organization_id' => Organization::query()->value('id') ?? 1,
            'category_id' => null,
            'title' => $title,
            'slug' => OrganizationDiscussion::uniqueSlugFromTitle($title),
            'body' => fake()->paragraphs(3, true),
            'attachments' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
            'is_pinned' => false,
            'is_locked' => false,
            'is_hidden' => false,
            'is_archived' => false,
            'posting_suspended' => false,
            'replies_count' => 0,
            'views_count' => fake()->numberBetween(0, 200),
            'reactions_count' => 0,
            'last_activity_at' => now()->subHours(fake()->numberBetween(1, 72)),
            'approved_at' => now(),
            'approved_by' => null,
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['is_locked' => true]);
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn () => ['organization_id' => $organization->id]);
    }

    public function inCategory(OrganizationDiscussionCategory $category): static
    {
        return $this->state(fn () => [
            'organization_id' => $category->organization_id,
            'category_id' => $category->id,
        ]);
    }
}
