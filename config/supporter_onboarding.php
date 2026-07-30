<?php

return [
    'total_steps' => 12,

    'notification_defaults' => [
        'organizations_followed' => true,
        'community_events' => true,
        'volunteer_opportunities' => true,
        'donations_campaigns' => true,
        'unity_meet_invitations' => true,
        'marketplace_offers' => true,
    ],

    /**
     * Simplified onboarding roles (saved to supporter_user_positions).
     */
    'roles' => [
        ['name' => 'Volunteer', 'slug' => 'onboarding-volunteer'],
        ['name' => 'Donor', 'slug' => 'onboarding-donor'],
        ['name' => 'Mentor', 'slug' => 'onboarding-mentor'],
        ['name' => 'Teacher', 'slug' => 'onboarding-teacher'],
        ['name' => 'Student', 'slug' => 'onboarding-student'],
        ['name' => 'Healthcare Professional', 'slug' => 'onboarding-healthcare-professional'],
        ['name' => 'Business Owner', 'slug' => 'onboarding-business-owner'],
        ['name' => 'Partnership Manager', 'slug' => 'onboarding-partnership-manager'],
        ['name' => 'Community Leader', 'slug' => 'onboarding-community-leader'],
        ['name' => 'Other', 'slug' => 'onboarding-other'],
    ],
];
