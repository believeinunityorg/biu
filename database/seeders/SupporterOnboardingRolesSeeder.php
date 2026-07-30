<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SupporterOnboardingRolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = config('supporter_onboarding.roles', []);
        $sortOrder = 9000;

        foreach ($roles as $role) {
            $exists = DB::table('supporter_positions')
                ->where('slug', $role['slug'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('supporter_positions')->insert([
                'name' => $role['name'],
                'slug' => $role['slug'],
                'description' => 'Supporter onboarding role',
                'category' => 'supporter_onboarding',
                'is_active' => true,
                'sort_order' => $sortOrder++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
