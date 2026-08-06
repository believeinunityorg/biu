<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExploreByCauseFakeDataSeeder extends Seeder
{
    public function run(): void
    {
        // Use the real primary_action_categories table (same as profile/edit Supporters Interest)
        $categories = DB::table('primary_action_categories')->pluck('id', 'slug');

        $userId = DB::table('users')->value('id') ?? 1;
        $orgId = DB::table('organizations')->value('id') ?? 1;
        // courses.organization_id FK references users.id (org owner), not organizations.id
        $courseOrgOwnerId = DB::table('organizations')->where('id', $orgId)->value('user_id') ?? $userId;

        // ── Organizations ─────────────────────────────────────────────────
        $organizations = [
            ['name' => 'HomeAid Shelter Network',        'slug' => 'housing',       'description' => 'Providing emergency shelter and housing assistance for homeless families.',        'city' => 'Springfield',   'state' => 'IL'],
            ['name' => 'Affordable Homes Initiative',    'slug' => 'housing',       'description' => 'Building affordable homes and advocating for housing policy reform.',              'city' => 'Chicago',       'state' => 'IL'],
            ['name' => 'Food Pantry Alliance',           'slug' => 'food',          'description' => 'Coordinating local food pantries to eliminate hunger in our community.',           'city' => 'Aurora',        'state' => 'IL'],
            ['name' => 'Fresh Harvest Network',          'slug' => 'food',          'description' => 'Distributing fresh produce to underserved neighborhoods every week.',              'city' => 'Rockford',      'state' => 'IL'],
            ['name' => 'Bright Futures Academy',         'slug' => 'education',     'description' => 'Providing after-school tutoring and mentorship for at-risk youth.',               'city' => 'Peoria',        'state' => 'IL'],
            ['name' => 'Literacy First Foundation',      'slug' => 'education',     'description' => 'Helping adults achieve basic literacy and GED certification.',                    'city' => 'Joliet',        'state' => 'IL'],
            ['name' => 'MindBridge Wellness',            'slug' => 'mental-health', 'description' => 'Connecting individuals to free and low-cost mental health services.',             'city' => 'Naperville',    'state' => 'IL'],
            ['name' => 'Hope & Healing Center',          'slug' => 'mental-health', 'description' => 'Crisis counseling and peer support for those struggling with mental illness.',    'city' => 'Evanston',      'state' => 'IL'],
            ['name' => 'Faith in Action Community',      'slug' => 'faith-based',   'description' => 'Uniting faith communities to serve local neighborhoods together.',               'city' => 'Waukegan',      'state' => 'IL'],
            ['name' => 'Jobs Ready Coalition',           'slug' => 'jobs',          'description' => 'Providing job training, resume help, and employment placement services.',         'city' => 'Elgin',         'state' => 'IL'],
            ['name' => 'Youth Empowerment League',       'slug' => 'youth',         'description' => 'Offering leadership programs, sports, and summer camps for young people.',       'city' => 'Springfield',   'state' => 'IL'],
            ['name' => 'Senior Care Partners',           'slug' => 'aging',         'description' => 'Delivering meals, rides, and companionship to elderly residents.',               'city' => 'Champaign',     'state' => 'IL'],
        ];

        foreach ($organizations as $data) {
            $pacId = $categories[$data['slug']] ?? null;
            if (! $pacId) continue;

            // Reuse existing org if name already exists
            $existingId = DB::table('organizations')->where('name', $data['name'])->value('id');
            if ($existingId) {
                DB::table('org_primary_action_category')->insertOrIgnore([
                    'organization_id'         => $existingId,
                    'primary_action_category_id' => $pacId,
                    'created_at'              => now(),
                    'updated_at'              => now(),
                ]);
                continue;
            }

            do {
                $ein = '99-' . str_pad(rand(1000000, 9999999), 7, '0', STR_PAD_LEFT);
            } while (DB::table('organizations')->where('ein', $ein)->exists());

            $newOrgId = DB::table('organizations')->insertGetId([
                'user_id'     => $userId,
                'ein'         => $ein,
                'name'        => $data['name'],
                'description' => $data['description'],
                'city'        => $data['city'],
                'state'       => $data['state'],
                'status'      => 'active',
                'email'       => Str::slug($data['name']) . '@example.org',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            DB::table('org_primary_action_category')->insertOrIgnore([
                'organization_id'            => $newOrgId,
                'primary_action_category_id' => $pacId,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        }

        // ── Events ────────────────────────────────────────────────────────
        // Events are linked to PAC via their organization, so we just need
        // to create events under the orgs we seeded above.
        $events = [
            ['title' => 'Housing Assistance Workshop',    'slug' => 'housing',       'start' => '+3 days',  'end' => '+3 days',  'address' => '512 Main Street',    'city' => 'Springfield', 'state' => 'IL', 'desc' => 'Learn about housing programs, rental assistance, and tenant rights.'],
            ['title' => 'Tiny Homes Build Day',           'slug' => 'housing',       'start' => '+10 days', 'end' => '+10 days', 'address' => '88 Elm Ave',         'city' => 'Chicago',     'state' => 'IL', 'desc' => 'Volunteer day to help frame and build tiny homes for homeless families.'],
            ['title' => 'Community Food Drive',           'slug' => 'food',          'start' => '+2 days',  'end' => '+4 days',  'address' => '200 Oak Street',     'city' => 'Aurora',      'state' => 'IL', 'desc' => 'Drop off non-perishable foods at collection points across the city.'],
            ['title' => 'Cooking for Community',          'slug' => 'food',          'start' => '+7 days',  'end' => '+7 days',  'address' => '44 Maple Blvd',      'city' => 'Rockford',    'state' => 'IL', 'desc' => 'Free cooking class teaching nutrition and meal prep on a budget.'],
            ['title' => 'Back to School Fair',            'slug' => 'education',     'start' => '+5 days',  'end' => '+5 days',  'address' => '900 School Road',    'city' => 'Peoria',      'state' => 'IL', 'desc' => 'Free school supplies, backpacks, and resources for families.'],
            ['title' => 'Mental Health Awareness Walk',   'slug' => 'mental-health', 'start' => '+6 days',  'end' => '+6 days',  'address' => 'Riverside Park',     'city' => 'Naperville',  'state' => 'IL', 'desc' => 'Join hundreds walking to raise awareness about mental health.'],
            ['title' => 'Faith Leaders Summit',           'slug' => 'faith-based',   'start' => '+8 days',  'end' => '+8 days',  'address' => '55 Church Lane',     'city' => 'Waukegan',    'state' => 'IL', 'desc' => 'Annual gathering of faith leaders to coordinate community outreach.'],
            ['title' => 'Job Fair: Hire Local',           'slug' => 'jobs',          'start' => '+4 days',  'end' => '+4 days',  'address' => 'City Convention Ctr', 'city' => 'Elgin',     'state' => 'IL', 'desc' => '50+ employers looking for local talent. Bring your resume!'],
            ['title' => 'Youth Leadership Summit',        'slug' => 'youth',         'start' => '+12 days', 'end' => '+13 days', 'address' => '101 Civic Center',   'city' => 'Springfield', 'state' => 'IL', 'desc' => 'Two-day event empowering teens with leadership and career skills.'],
            ['title' => 'Senior Tech Help Day',           'slug' => 'aging',         'start' => '+9 days',  'end' => '+9 days',  'address' => 'Champaign Library',   'city' => 'Champaign',  'state' => 'IL', 'desc' => 'Volunteers helping seniors with smartphones, tablets, and internet safety.'],
        ];

        foreach ($events as $data) {
            $pacId = $categories[$data['slug']] ?? null;
            if (! $pacId) continue;

            // Find an org linked to this PAC to attach the event to
            $linkedOrgId = DB::table('org_primary_action_category')
                ->where('primary_action_category_id', $pacId)
                ->value('organization_id') ?? $orgId;

            $exists = DB::table('events')
                ->where('name', $data['title'])
                ->where('organization_id', $linkedOrgId)
                ->exists();

            if (! $exists) {
                DB::table('events')->insert([
                    'organization_id' => $linkedOrgId,
                    'user_id'         => $userId,
                    'name'            => $data['title'],
                    'description'     => $data['desc'],
                    'start_date'      => now()->modify($data['start']),
                    'end_date'        => now()->modify($data['end']),
                    'address'         => $data['address'],
                    'city'            => $data['city'],
                    'state'           => $data['state'],
                    'status'          => 'active',
                    'visibility'      => 'public',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }

        // ── Courses (linked via course_pac) ───────────────────────────────
        $courses = [
            ['title' => 'Tenant Rights Training',         'slug' => 'housing',       'format' => 'online',    'pricing' => 'free', 'fee' => null, 'desc' => 'In-depth training on tenant rights, lease agreements, and eviction prevention.'],
            ['title' => 'Nutrition on a Budget',          'slug' => 'food',          'format' => 'in-person', 'pricing' => 'free', 'fee' => null, 'desc' => 'Learn to prepare healthy, balanced meals while stretching every dollar.'],
            ['title' => 'Adult Literacy Bootcamp',        'slug' => 'education',     'format' => 'hybrid',    'pricing' => 'free', 'fee' => null, 'desc' => 'Eight-week intensive reading and writing program for adult learners.'],
            ['title' => 'GED Prep Course',                'slug' => 'education',     'format' => 'online',    'pricing' => 'free', 'fee' => null, 'desc' => 'Self-paced GED preparation covering math, science, language arts, and social studies.'],
            ['title' => 'Stress & Anxiety Management',    'slug' => 'mental-health', 'format' => 'online',    'pricing' => 'free', 'fee' => null, 'desc' => 'Evidence-based techniques for managing daily stress and anxiety.'],
            ['title' => 'Peer Support Specialist Cert.',  'slug' => 'mental-health', 'format' => 'in-person', 'pricing' => 'paid', 'fee' => 49,   'desc' => 'Become a certified peer support specialist and help others in your community.'],
            ['title' => 'Faith-Based Leadership 101',     'slug' => 'faith-based',   'format' => 'online',    'pricing' => 'free', 'fee' => null, 'desc' => 'Equip church and ministry leaders with practical skills for community impact.'],
            ['title' => 'Resume & Interview Skills',      'slug' => 'jobs',          'format' => 'hybrid',    'pricing' => 'free', 'fee' => null, 'desc' => 'Build a standout resume and practice interview techniques with real employers.'],
            ['title' => 'Youth Entrepreneurship',         'slug' => 'youth',         'format' => 'in-person', 'pricing' => 'free', 'fee' => null, 'desc' => 'Teaching teens how to start and run a small business from idea to launch.'],
            ['title' => 'Caregiver Essentials',           'slug' => 'aging',         'format' => 'online',    'pricing' => 'free', 'fee' => null, 'desc' => 'Practical skills for family caregivers supporting aging loved ones at home.'],
        ];

        foreach ($courses as $data) {
            $pacId = $categories[$data['slug']] ?? null;
            if (! $pacId) continue;

            $existingId = DB::table('courses')->where('name', $data['title'])->value('id');

            if (! $existingId) {
                $courseSlug = Str::slug($data['title']) . '-' . Str::random(5);
                $existingId = DB::table('courses')->insertGetId([
                    'organization_id' => $courseOrgOwnerId,
                    'user_id'         => $userId,
                    'name'            => $data['title'],
                    'slug'            => $courseSlug,
                    'description'     => $data['desc'],
                    'format'          => $data['format'],
                    'pricing_type'    => $data['pricing'],
                    'course_fee'      => $data['fee'],
                    'start_date'      => now()->addDays(rand(7, 30)),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }

            DB::table('course_pac')->insertOrIgnore([
                'course_id'                  => $existingId,
                'primary_action_category_id' => $pacId,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        }

        // ── Volunteer / Job Posts (linked via job_post_pac) ───────────────
        $positionId = DB::table('job_positions')->value('id');
        if (! $positionId) {
            $positionId = DB::table('job_positions')->insertGetId([
                'title'      => 'Volunteer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $volunteers = [
            ['title' => 'Homeless Shelter Night Helper',  'slug' => 'housing',       'city' => 'Springfield', 'state' => 'IL', 'desc' => 'Assist staff during overnight shelter shifts, help with check-in and meal service.'],
            ['title' => 'Move-In Day Volunteer',          'slug' => 'housing',       'city' => 'Chicago',     'state' => 'IL', 'desc' => 'Help families carry furniture and set up their new affordable housing units.'],
            ['title' => 'Food Pantry Sorter',             'slug' => 'food',          'city' => 'Aurora',      'state' => 'IL', 'desc' => 'Sort and package food donations for weekly distribution to families in need.'],
            ['title' => 'Meals on Wheels Driver',         'slug' => 'food',          'city' => 'Rockford',    'state' => 'IL', 'desc' => 'Deliver hot meals to elderly and homebound individuals 2–3 times per week.'],
            ['title' => 'After-School Tutor',             'slug' => 'education',     'city' => 'Peoria',      'state' => 'IL', 'desc' => 'Tutor K-8 students in math and reading two evenings per week.'],
            ['title' => 'Crisis Hotline Volunteer',       'slug' => 'mental-health', 'city' => 'Naperville',  'state' => 'IL', 'desc' => 'Provide empathetic phone support to individuals in mental health crisis after training.'],
            ['title' => 'Faith Outreach Coordinator',     'slug' => 'faith-based',   'city' => 'Waukegan',    'state' => 'IL', 'desc' => 'Coordinate volunteer schedules and community events across partner faith organizations.'],
            ['title' => 'Job Coach & Mock Interviewer',   'slug' => 'jobs',          'city' => 'Elgin',       'state' => 'IL', 'desc' => 'Help job seekers practice interview skills and build professional confidence.'],
            ['title' => 'Youth Mentor',                   'slug' => 'youth',         'city' => 'Springfield', 'state' => 'IL', 'desc' => 'Meet with an assigned youth mentee weekly to offer guidance and encouragement.'],
            ['title' => 'Senior Companion Visitor',       'slug' => 'aging',         'city' => 'Champaign',   'state' => 'IL', 'desc' => 'Visit isolated elderly residents for friendly conversation and light errands.'],
        ];

        foreach ($volunteers as $data) {
            $pacId = $categories[$data['slug']] ?? null;
            if (! $pacId) continue;

            $existingId = DB::table('job_posts')->where('title', $data['title'])->value('id');

            if (! $existingId) {
                $existingId = DB::table('job_posts')->insertGetId([
                    'organization_id'            => $orgId,
                    'position_id'                => $positionId,
                    'title'                      => $data['title'],
                    'description'                => $data['desc'],
                    'type'                       => 'volunteer',
                    'location_type'              => 'on-site',
                    'city'                       => $data['city'],
                    'state'                      => $data['state'],
                    'country'                    => 'US',
                    'status'                     => 'active',
                    'date_posted'                => now(),
                    'application_deadline'       => now()->addDays(30)->toDateString(),
                    'time_commitment_min_hours'  => rand(2, 8),
                    'created_at'                 => now(),
                    'updated_at'                 => now(),
                ]);
            }

            DB::table('job_post_pac')->insertOrIgnore([
                'job_post_id'                => $existingId,
                'primary_action_category_id' => $pacId,
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        }

        $this->command->info('✅ Explore by Cause fake data seeded (using primary_action_categories)');
        $this->command->info('   • ' . count($organizations) . ' organizations linked via org_primary_action_category');
        $this->command->info('   • ' . count($events)        . ' events under org-linked organizations');
        $this->command->info('   • ' . count($courses)       . ' courses linked via course_pac');
        $this->command->info('   • ' . count($volunteers)    . ' volunteer posts linked via job_post_pac');
    }
}
