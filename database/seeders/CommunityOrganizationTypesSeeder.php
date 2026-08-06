<?php

namespace Database\Seeders;

use App\Models\CommunityOrganizationType;
use Illuminate\Database\Seeder;

class CommunityOrganizationTypesSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['slug' => 'nonprofit', 'name' => 'Nonprofit Organization'],
            ['slug' => 'church_religious', 'name' => 'Church / Religious Organization'],
            ['slug' => 'school_educational', 'name' => 'School / Educational Institution'],
            ['slug' => 'college_university', 'name' => 'College / University'],
            ['slug' => 'alumni_association', 'name' => 'Alumni Association'],
            ['slug' => 'family_reunion', 'name' => 'Family Reunion'],
            ['slug' => 'business', 'name' => 'Business'],
            ['slug' => 'business_association', 'name' => 'Business Association'],
            ['slug' => 'chamber_of_commerce', 'name' => 'Chamber of Commerce'],
            ['slug' => 'professional_association', 'name' => 'Professional Association'],
            ['slug' => 'community_organization', 'name' => 'Community Organization'],
            ['slug' => 'community_club', 'name' => 'Community Club'],
            ['slug' => 'neighborhood_association', 'name' => 'Neighborhood Association'],
            ['slug' => 'civic_organization', 'name' => 'Civic Organization'],
            ['slug' => 'sports_organization', 'name' => 'Sports Organization'],
            ['slug' => 'youth_organization', 'name' => 'Youth Organization'],
            ['slug' => 'senior_organization', 'name' => 'Senior Organization'],
            ['slug' => 'fraternity', 'name' => 'Fraternity'],
            ['slug' => 'sorority', 'name' => 'Sorority'],
            ['slug' => 'veterans_organization', 'name' => 'Veterans Organization'],
            ['slug' => 'labor_union', 'name' => 'Labor Union'],
            ['slug' => 'political_organization', 'name' => 'Political Organization'],
            ['slug' => 'foundation', 'name' => 'Foundation'],
            ['slug' => 'charity', 'name' => 'Charity'],
            ['slug' => 'social_club', 'name' => 'Social Club'],
            ['slug' => 'cultural_organization', 'name' => 'Cultural Organization'],
            ['slug' => 'arts_organization', 'name' => 'Arts Organization'],
            ['slug' => 'music_organization', 'name' => 'Music Organization'],
            ['slug' => 'performing_arts_organization', 'name' => 'Performing Arts Organization'],
            ['slug' => 'animal_rescue_welfare', 'name' => 'Animal Rescue / Animal Welfare'],
            ['slug' => 'environmental_organization', 'name' => 'Environmental Organization'],
            ['slug' => 'healthcare_organization', 'name' => 'Healthcare Organization'],
            ['slug' => 'support_group', 'name' => 'Support Group'],
            ['slug' => 'advocacy_organization', 'name' => 'Advocacy Organization'],
            ['slug' => 'volunteer_organization', 'name' => 'Volunteer Organization'],
            ['slug' => 'government_agency', 'name' => 'Government Agency'],
            ['slug' => 'public_safety_organization', 'name' => 'Public Safety Organization'],
            ['slug' => 'first_responder_organization', 'name' => 'First Responder Organization'],
            ['slug' => 'international_organization', 'name' => 'International Organization'],
            ['slug' => 'other', 'name' => 'Other'],
        ];

        foreach ($items as $index => $row) {
            CommunityOrganizationType::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => $row['name'],
                    'sort_order' => ($index + 1) * 10,
                    'is_active' => true,
                ]
            );
        }
    }
}
