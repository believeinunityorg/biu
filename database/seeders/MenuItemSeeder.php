<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class MenuItemSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['menu_key' => 'home', 'title' => 'Home', 'category' => 'home', 'href' => '/', 'icon' => 'Home', 'active_path_prefix' => '/', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'sort_order' => 1],
            ['menu_key' => 'dashboard', 'title' => 'Dashboard', 'category' => 'home', 'route_name' => 'dashboard', 'icon' => 'Activity', 'active_path_prefix' => '/dashboard', 'default_enabled' => true, 'supporter_visible' => false, 'bottom_nav_eligible' => true, 'requires_auth' => true, 'sort_order' => 2],
            ['menu_key' => 'donate', 'title' => 'Donate', 'category' => 'give', 'route_name' => 'donate', 'icon' => 'Heart', 'active_path_prefix' => '/donate', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['supporting_organizations', 'gift_cards'], 'sort_order' => 10],
            ['menu_key' => 'fundme', 'title' => 'FundMe', 'category' => 'give', 'route_name' => 'fundme.index', 'icon' => 'Megaphone', 'active_path_prefix' => '/believe-fundme', 'default_enabled' => false, 'interest_tags' => ['supporting_organizations'], 'sort_order' => 11],
            ['menu_key' => 'gift_cards', 'title' => 'My Gift Cards', 'category' => 'give', 'route_name' => 'gift-cards.my-cards', 'icon' => 'Gift', 'active_path_prefix' => '/gift-cards/my-cards', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'requires_auth' => true, 'interest_tags' => ['gift_cards', 'shopping_deals'], 'sort_order' => 12],
            ['menu_key' => 'gift_bp', 'title' => 'Gift BP', 'category' => 'give', 'route_name' => 'gift-bp.index', 'icon' => 'Sparkles', 'active_path_prefix' => '/gift-bp', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'requires_auth' => true, 'interest_tags' => ['gift_cards', 'supporting_organizations', 'community_events'], 'sort_order' => 13],
            ['menu_key' => 'campaigns', 'title' => 'Campaigns', 'category' => 'give', 'route_name' => 'fundme.index', 'icon' => 'HeartHandshake', 'active_path_prefix' => '/believe-fundme', 'default_enabled' => false, 'interest_tags' => ['supporting_organizations'], 'sort_order' => 13],
            ['menu_key' => 'organizations', 'title' => 'Organizations', 'category' => 'community', 'route_name' => 'organizations', 'icon' => 'Building2', 'active_path_prefix' => '/organizations', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['supporting_organizations', 'business_networking'], 'sort_order' => 20],
            ['menu_key' => 'events', 'title' => 'Events', 'category' => 'community', 'route_name' => 'alleventsPage', 'icon' => 'Calendar', 'active_path_prefix' => '/all-events', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['community_events', 'volunteering', 'faith_ministry'], 'sort_order' => 21],
            ['menu_key' => 'groups', 'title' => 'Groups', 'category' => 'community', 'route_name' => 'groups', 'icon' => 'Users', 'active_path_prefix' => '/groups', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['companion_hub', 'community_events', 'faith_ministry'], 'sort_order' => 22],
            ['menu_key' => 'chat', 'title' => 'Chat', 'category' => 'community', 'route_name' => 'chat.index', 'icon' => 'MessageCircle', 'active_path_prefix' => '/chat', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['companion_hub'], 'sort_order' => 23],
            ['menu_key' => 'communication_hub', 'title' => 'Announcements & Discussion', 'category' => 'community', 'route_name' => 'user.communication-hub.index', 'icon' => 'Megaphone', 'active_path_prefix' => '/supporter/communication-hub', 'default_enabled' => true, 'requires_auth' => true, 'supporter_visible' => true, 'org_visible' => false, 'admin_visible' => false, 'bottom_nav_eligible' => true, 'interest_tags' => ['supporting_organizations', 'community_events'], 'sort_order' => 24],
            ['menu_key' => 'social_feed', 'title' => 'Social Feed', 'category' => 'community', 'route_name' => 'social-feed.index', 'icon' => 'Users', 'active_path_prefix' => '/social-feed', 'default_enabled' => false, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['community_events'], 'sort_order' => 25],
            ['menu_key' => 'volunteers', 'title' => 'Volunteers', 'category' => 'community', 'route_name' => 'volunteer-opportunities.index', 'icon' => 'HeartHandshake', 'active_path_prefix' => '/volunteer-opportunities', 'default_enabled' => false, 'bottom_nav_eligible' => true, 'interest_tags' => ['volunteering'], 'sort_order' => 26],
            ['menu_key' => 'companion_hub', 'title' => 'Companion Hub', 'category' => 'learning', 'href' => '/courses?type=companion', 'icon' => 'GraduationCap', 'active_path_prefix' => '/courses', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['companion_hub', 'faith_ministry', 'education'], 'sort_order' => 30],
            ['menu_key' => 'learning_hub', 'title' => 'Learning Hub', 'category' => 'learning', 'href' => '/courses?type=learning', 'icon' => 'BookOpen', 'active_path_prefix' => '/courses', 'default_enabled' => false, 'bottom_nav_eligible' => true, 'interest_tags' => ['education'], 'sort_order' => 31],
            ['menu_key' => 'challenge_hub', 'title' => 'Challenge Hub', 'category' => 'learning', 'route_name' => 'challenge-hub.index', 'icon' => 'Trophy', 'active_path_prefix' => '/challenge-hub', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['education', 'volunteering'], 'sort_order' => 32],
            ['menu_key' => 'marketplace', 'title' => 'Marketplace', 'category' => 'earn_save', 'route_name' => 'marketplace.index', 'icon' => 'Store', 'active_path_prefix' => '/marketplace', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['shopping_deals'], 'sort_order' => 40],
            ['menu_key' => 'merchant_deals', 'title' => 'Merchant Deals', 'category' => 'earn_save', 'route_name' => 'merchant-hub.index', 'icon' => 'ShoppingBag', 'active_path_prefix' => '/merchant-hub', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['shopping_deals'], 'sort_order' => 41],
            ['menu_key' => 'sweepstakes', 'title' => 'Sweepstakes', 'category' => 'earn_save', 'route_name' => 'frontend.raffles.index', 'icon' => 'Ticket', 'active_path_prefix' => '/frontend/raffles', 'default_enabled' => false, 'requires_auth' => true, 'sort_order' => 42],
            ['menu_key' => 'feedback_campaigns', 'title' => 'Feedback Campaigns', 'category' => 'earn_save', 'route_name' => 'feedback-campaigns.index', 'icon' => 'MessageSquare', 'default_enabled' => false, 'requires_auth' => true, 'sort_order' => 43],
            ['menu_key' => 'service_hub', 'title' => 'Service Hub', 'category' => 'earn_save', 'route_name' => 'service-hub.index', 'icon' => 'Handshake', 'active_path_prefix' => '/service-hub', 'default_enabled' => false, 'bottom_nav_eligible' => true, 'interest_tags' => ['business_networking'], 'sort_order' => 44],
            ['menu_key' => 'unity_meet', 'title' => 'Unity Meet', 'category' => 'media', 'route_name' => 'livestreams.supporter.index', 'icon' => 'Video', 'active_path_prefix' => '/livestreams/supporter', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['content_creation', 'faith_ministry'], 'sort_order' => 50],
            ['menu_key' => 'unity_live', 'title' => 'Unity Live', 'category' => 'media', 'route_name' => 'unity-live.index', 'icon' => 'Radio', 'active_path_prefix' => '/unity-live', 'default_enabled' => false, 'bottom_nav_eligible' => true, 'interest_tags' => ['content_creation'], 'sort_order' => 51],
            ['menu_key' => 'unity_videos', 'title' => 'Unity Videos', 'category' => 'media', 'href' => '/unity-videos', 'icon' => 'Video', 'active_path_prefix' => '/unity-videos', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['content_creation'], 'sort_order' => 52],
            ['menu_key' => 'nonprofit_news', 'title' => 'News', 'category' => 'media', 'href' => '/nonprofit-news', 'icon' => 'Newspaper', 'active_path_prefix' => '/nonprofit-news', 'default_enabled' => false, 'sort_order' => 53],
            ['menu_key' => 'explore', 'title' => 'Explore', 'category' => 'community', 'href' => '/explore-by-cause', 'icon' => 'Compass', 'active_path_prefix' => '/explore-by-cause', 'default_enabled' => false, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'sort_order' => 27],
            ['menu_key' => 'find_supporters', 'title' => 'Find Supporters', 'category' => 'community', 'route_name' => 'find-supporters.index', 'icon' => 'UserPlus', 'active_path_prefix' => '/find-supporters', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['community_events', 'companion_hub', 'supporting_organizations'], 'sort_order' => 28],
            ['menu_key' => 'kiosk', 'title' => 'Kiosk', 'category' => 'community', 'route_name' => 'kiosk.index', 'icon' => 'Monitor', 'active_path_prefix' => '/kiosk', 'default_enabled' => true, 'bottom_nav_eligible' => true, 'interest_tags' => ['volunteering', 'business_networking', 'community_events'], 'sort_order' => 29],
            ['menu_key' => 'donation_history', 'title' => 'Donation History', 'category' => 'give', 'route_name' => 'profile.donations', 'icon' => 'Clock', 'active_path_prefix' => '/profile/donations', 'default_enabled' => false, 'requires_auth' => true, 'sort_order' => 14],
            ['menu_key' => 'wallet', 'title' => 'Wallet', 'category' => 'account', 'icon' => 'Wallet', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => true, 'admin_visible' => false, 'sort_order' => 85],
            ['menu_key' => 'profile', 'title' => 'Profile', 'category' => 'account', 'route_name' => 'user.profile.index', 'icon' => 'User', 'active_path_prefix' => '/profile', 'default_enabled' => true, 'requires_auth' => true, 'bottom_nav_eligible' => false, 'sort_order' => 90],
        ];

        foreach ($items as $item) {
            MenuItem::query()->updateOrCreate(
                ['menu_key' => $item['menu_key']],
                array_merge([
                    'supporter_visible' => true,
                    'org_visible' => true,
                    'admin_visible' => true,
                    'requires_auth' => false,
                    'bottom_nav_eligible' => false,
                    'is_active' => true,
                    'interest_tags' => null,
                ], $item),
            );
        }
    }
}
