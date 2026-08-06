<?php

namespace Database\Seeders;

use Database\Seeders\Support\SeederRunTracker;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Each seeder class is recorded in the `seed_runs` table after it finishes successfully.
     * Re-running `php artisan db:seed` skips classes that already completed.
     * Set SEED_FORCE=true in .env to run every seeder again (still records completion).
     */
    public function run(): void
    {
        // Run seeders sequentially (serialized) in the correct order

        // Step 1: Seed code tables first (foundation data)
        $this->callUnlessSeeded(ActivityCodesTableSeeder::class);
        $this->callUnlessSeeded(AffiliationCodesTableSeeder::class);
        $this->callUnlessSeeded(ClassificationCodesTableSeeder::class);
        $this->callUnlessSeeded(DeductibilityCodesTableSeeder::class);
        $this->callUnlessSeeded(FoundationTypesTableSeeder::class);
        $this->callUnlessSeeded(GroupCodesTableSeeder::class);
        $this->callUnlessSeeded(NteeCodesTableSeeder::class);
        $this->callUnlessSeeded(OrganizationStructuresTableSeeder::class);
        $this->callUnlessSeeded(StatusCodesTableSeeder::class);
        $this->callUnlessSeeded(SubsectionCodesTableSeeder::class);
        $this->callUnlessSeeded(CountriesSeeder::class);

        // Step 2: Seed roles and permissions
        $this->callUnlessSeeded(RoleSeeder::class);
        $this->callUnlessSeeded(AdminPermissionsSeeder::class);
        $this->callUnlessSeeded(permissionsSeeder::class);
        $this->callUnlessSeeded(ComprehensivePermissionsSeeder::class);
        $this->callUnlessSeeded(ComplianceReviewPermissionSeeder::class);
        $this->callUnlessSeeded(JobPostsPermisstionSeeder::class);
        $this->callUnlessSeeded(ProjectPermissionsSeeder::class);
        $this->callUnlessSeeded(LivestockPermissionsSeeder::class);
        $this->callUnlessSeeded(assignRole::class);

        // Step 3: Seed users and assign roles/permissions
        $this->callUnlessSeeded(AssignRolePermissionToUsersSeeder::class);
        $this->callUnlessSeeded(UsernameSeeder::class);
        $this->callUnlessSeeded(OrganizationPendingRoleSeeder::class);
        $this->callUnlessSeeded(testUserSeeder::class);
        $this->callUnlessSeeded(TestOrganizationSeeder::class);
        $this->callUnlessSeeded(GenerateSlugsSeeder::class);

        // Step 4: Seed fractional ownership test data (needs users/orgs)
        $this->callUnlessSeeded(FractionalOwnershipSeeder::class);

        // Step 5: Seed categories and other content
        $this->callUnlessSeeded(CategorySeeder::class);
        $this->callUnlessSeeded(InterestCategorySeeder::class);
        $this->callUnlessSeeded(PositionCategoriesAndJobPositionsSeeder::class);
        $this->callUnlessSeeded(EventTypesTableSeeder::class);
        $this->callUnlessSeeded(CompanionEventTypesSeeder::class);
        $this->callUnlessSeeded(ChatRoomsSeeder::class);
        $this->callUnlessSeeded(PlanSeeder::class);
        $this->callUnlessSeeded(PlanPricingPageSeeder::class);
        $this->callUnlessSeeded(WalletPlanSeeder::class);
        $this->callUnlessSeeded(SmsPackageSeeder::class);
        $this->callUnlessSeeded(ContactPageContentSeeder::class);
        $this->callUnlessSeeded(PromotionalBannerSeeder::class);
        $this->callUnlessSeeded(FundMeCategorySeeder::class);
        $this->callUnlessSeeded(ChallengeHubCategoriesSeeder::class);
        $this->callUnlessSeeded(ChallengeQuizSubcategoriesSeeder::class);
        $this->callUnlessSeeded(LevelUpChallengeEntriesSeeder::class);
        $this->callUnlessSeeded(GiftOccasionSeeder::class);
        $this->callUnlessSeeded(BarterCategoriesAndBenefitGroupsSeeder::class);

        // Step 6: Seed organization-related data
        $this->callUnlessSeeded(PrimaryActionCategoriesSeeder::class);
        $this->callUnlessSeeded(CommunityOrganizationTypesSeeder::class);
        $this->callUnlessSeeded(OrganizationBoardMembersSeeder::class);
        $this->callUnlessSeeded(OrganizationRoleSeeder::class);
        $this->callUnlessSeeded(CommunicationHubSeeder::class);

        // Step 7: Seed additional data and utilities
        $this->callUnlessSeeded(RegenerateStripeCustomerIdSeeder::class);
        $this->callUnlessSeeded(ExistingUserReferralGenerate::class);

        $this->callUnlessSeeded(SupporterPositionsTableSeeder::class);
        $this->callUnlessSeeded(SupporterOnboardingRolesSeeder::class);
        $this->callUnlessSeeded(SupporterActivityDemoSeeder::class);

        // Step 8: Seed merchant data
        $this->callUnlessSeeded(MerchantSeeder::class);
        $this->callUnlessSeeded(MerchantSubscriptionPlanSeeder::class);
        $this->callUnlessSeeded(MerchantHubCategorySeeder::class);
        $this->callUnlessSeeded(MerchantHubMerchantSeeder::class);
        $this->callUnlessSeeded(MerchantHubOfferSeeder::class);

        $this->callUnlessSeeded(StateSalesTaxCompleteSeeder::class);
        $this->callUnlessSeeded(StateSalesTaxExemptionDataSeeder::class);
        $this->callUnlessSeeded(ServiceCategorySeeder::class);

        $this->callUnlessSeeded(SellerSkillsSeeder::class);
        $this->callUnlessSeeded(LanguagesSeeder::class);

        $this->callUnlessSeeded(CommunityVideosSeeder::class);
        $this->callUnlessSeeded(ExploreByCauseFakeDataSeeder::class);
        $this->callUnlessSeeded(UnityLoavesSeeder::class);

        // Kiosk data
        $this->callUnlessSeeded(KioskCategoriesSeeder::class);
        $this->callUnlessSeeded(UsStatesSeeder::class);
        $this->callUnlessSeeded(KioskSubcategoriesSeeder::class);
        $this->callUnlessSeeded(MenuItemSeeder::class);

        // Livestock demo data (after livestock permissions / users exist)
        $this->callUnlessSeeded(GoatAnimalsSeeder::class);
        $this->callUnlessSeeded(CreateStripeCustomerForLivestockUsersSeeder::class);

        // After all seeders: care_alliance role must match organization permissions (Spatie).
        $this->callUnlessSeeded(CareAllianceMirrorOrganizationPermissionsSeeder::class);
    }

    /**
     * Run a seeder only if it has not completed successfully in a previous `db:seed` run.
     */
    protected function callUnlessSeeded(string $class): void
    {
        if (! SeederRunTracker::shouldRun($class)) {
            $this->command?->info("Skipping {$class} (already seeded).");

            return;
        }

        $this->call($class);

        SeederRunTracker::markCompleted($class);
    }
}
