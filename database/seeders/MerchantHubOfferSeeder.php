<?php

namespace Database\Seeders;

use App\Models\MerchantHubCategory;
use App\Models\MerchantHubMerchant;
use App\Models\MerchantHubOffer;
use Illuminate\Database\Seeder;

class MerchantHubOfferSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $resolveCategory = function (string ...$slugs): ?MerchantHubCategory {
            foreach ($slugs as $slug) {
                $category = MerchantHubCategory::query()->where('slug', $slug)->first();
                if ($category) {
                    return $category;
                }
            }

            return MerchantHubCategory::query()->orderBy('id')->first();
        };

        $giftCards = $resolveCategory('gift-cards', 'retail-shopping', 'food-dining');
        $services = $resolveCategory('services', 'professional-services', 'health-wellness');
        $electronics = $resolveCategory('electronics', 'technology-services', 'retail-shopping');
        $dining = $resolveCategory('dining', 'food-dining');
        $entertainment = $resolveCategory('entertainment', 'events-entertainment');

        $retailStore = MerchantHubMerchant::where('slug', 'retail-store')->first();
        $fitnessCenter = MerchantHubMerchant::where('slug', 'fitness-center')->first();
        $techStore = MerchantHubMerchant::where('slug', 'tech-store')->first();
        $restaurant = MerchantHubMerchant::where('slug', 'fine-dining-restaurant')->first();
        $spa = MerchantHubMerchant::where('slug', 'luxury-spa')->first();
        $cinema = MerchantHubMerchant::where('slug', 'cinema-complex')->first();

        $offers = [
            [
                'merchant' => $retailStore,
                'category' => $giftCards,
                'title' => 'Gift Card - $50 Value',
                'short_description' => 'Use reward points toward a $50 gift card',
                'description' => 'Get a $50 gift card that you can use for any purchase at our retail store. Perfect for yourself or as a gift for someone special.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 5000,
                'cash_required' => 10.00,
                'currency' => 'USD',
                'inventory_qty' => 100,
                'status' => 'active',
            ],
            [
                'merchant' => $fitnessCenter,
                'category' => $services,
                'title' => 'Fitness Class Pass',
                'short_description' => 'Unlimited classes for one month',
                'description' => 'Get unlimited access to all fitness classes for one full month. Perfect for trying out different workout styles and finding your favorite.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 7500,
                'cash_required' => null,
                'currency' => 'USD',
                'inventory_qty' => 50,
                'status' => 'active',
            ],
            [
                'merchant' => $techStore,
                'category' => $electronics,
                'title' => 'Wireless Earbuds',
                'short_description' => 'Premium wireless earbuds with noise cancellation',
                'description' => 'High-quality wireless earbuds featuring active noise cancellation, superior sound quality, and long battery life. Perfect for music lovers and commuters.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 10000,
                'cash_required' => 25.00,
                'currency' => 'USD',
                'inventory_qty' => 30,
                'status' => 'active',
            ],
            [
                'merchant' => $restaurant,
                'category' => $dining,
                'title' => 'Dinner for Two',
                'short_description' => 'Three-course dinner for two people',
                'description' => 'Enjoy a romantic three-course dinner for two at our fine dining restaurant. Includes appetizer, main course, and dessert. Perfect for date night or special occasions.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 8000,
                'cash_required' => 30.00,
                'currency' => 'USD',
                'inventory_qty' => 40,
                'status' => 'active',
            ],
            [
                'merchant' => $spa,
                'category' => $services,
                'title' => 'Spa Day Package',
                'short_description' => 'Full day spa experience with massage and treatments',
                'description' => 'Treat yourself to a full day spa experience including a relaxing massage, facial treatment, and access to our spa facilities. A perfect way to unwind and recharge.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 12000,
                'cash_required' => 50.00,
                'currency' => 'USD',
                'inventory_qty' => 20,
                'status' => 'active',
            ],
            [
                'merchant' => $cinema,
                'category' => $entertainment,
                'title' => 'Movie Theater Tickets',
                'short_description' => 'Two tickets to any movie',
                'description' => 'Get two tickets to any movie showing at our cinema complex. Perfect for a night out with friends or family. Valid for any regular screening.',
                'image_url' => '/placeholder.jpg',
                'points_required' => 3000,
                'cash_required' => null,
                'currency' => 'USD',
                'inventory_qty' => 100,
                'status' => 'active',
            ],
        ];

        $seeded = 0;
        foreach ($offers as $offer) {
            if (! $offer['merchant'] || ! $offer['category']) {
                $this->command?->warn("Skipping offer \"{$offer['title']}\" — missing merchant or category.");

                continue;
            }

            MerchantHubOffer::updateOrCreate(
                [
                    'merchant_hub_merchant_id' => $offer['merchant']->id,
                    'title' => $offer['title'],
                ],
                [
                    'merchant_hub_merchant_id' => $offer['merchant']->id,
                    'merchant_hub_category_id' => $offer['category']->id,
                    'title' => $offer['title'],
                    'short_description' => $offer['short_description'],
                    'description' => $offer['description'],
                    'image_url' => $offer['image_url'],
                    'points_required' => $offer['points_required'],
                    'cash_required' => $offer['cash_required'],
                    'currency' => $offer['currency'],
                    'inventory_qty' => $offer['inventory_qty'],
                    'status' => $offer['status'],
                ]
            );
            $seeded++;
        }

        $this->command?->info("Merchant hub offers seeded: {$seeded}");
    }
}
