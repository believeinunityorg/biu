<?php

namespace Database\Seeders;

use App\Models\ContactPageContent;
use Illuminate\Database\Seeder;

class ContactPageContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing content (optional - comment out if you want to keep existing data)
        ContactPageContent::truncate();

        // Hero Section
        ContactPageContent::create([
            'section' => 'hero',
            'content' => [
                'badge_text' => "We're Here to Help",
                'title' => 'Get in Touch',
                'description' => "Have questions? We're here to help. Reach out to us and we'll get back to you as soon as possible.",
            ],
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // Contact Methods
        $contactMethods = [
            [
                'title' => 'Email Support',
                'description' => "Send us an email and we'll respond within 24 hours",
                'contact' => 'support@believeinunity.org',
                'action' => 'mailto:support@believeinunity.org',
                'color' => 'from-blue-500 to-blue-600',
                'bgColor' => 'bg-blue-50 dark:bg-blue-900/20',
                'iconColor' => 'text-blue-600 dark:text-blue-400',
            ],
            [
                'title' => 'Phone Support',
                'description' => 'Speak with our support team directly',
                'contact' => '+1 (555) 123-4567',
                'action' => 'tel:+15551234567',
                'color' => 'from-green-500 to-green-600',
                'bgColor' => 'bg-green-50 dark:bg-green-900/20',
                'iconColor' => 'text-green-600 dark:text-green-400',
            ],
            [
                'title' => 'Live Chat',
                'description' => 'Chat with us in real-time during business hours',
                'contact' => 'Available 9 AM - 5 PM EST',
                'action' => '#',
                'color' => 'from-purple-500 to-purple-600',
                'bgColor' => 'bg-purple-50 dark:bg-purple-900/20',
                'iconColor' => 'text-purple-600 dark:text-purple-400',
            ],
        ];

        foreach ($contactMethods as $index => $method) {
            ContactPageContent::create([
                'section' => 'contact_methods',
                'content' => $method,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        // FAQ Items
        $faqItems = [
            [
                'question' => "How do I know my donation is secure?",
                'answer' => "We use industry-standard encryption and partner with trusted payment processors like Stripe to ensure your donation is completely secure.",
            ],
            [
                'question' => "Can I get a tax receipt for my donation?",
                'answer' => "Yes! All donations through Believe in Unity are tax-deductible, and you'll receive an official receipt via email immediately after your donation.",
            ],
            [
                'question' => "How do I track the impact of my donation?",
                'answer' => "You can log into your account to see detailed reports on how your donations are being used and the impact they're creating.",
            ],
            [
                'question' => "How are organizations verified?",
                'answer' => "We have a rigorous verification process that includes checking legal status, financial transparency, and impact measurement capabilities.",
            ],
        ];

        foreach ($faqItems as $index => $faq) {
            ContactPageContent::create([
                'section' => 'faq',
                'content' => $faq,
                'sort_order' => $index,
                'is_active' => true,
            ]);
        }

        // Office Hours
        ContactPageContent::create([
            'section' => 'office_hours',
            'content' => [
                'day_range' => 'Monday - Friday',
                'hours' => '9:00 AM - 6:00 PM EST',
                'saturday_day' => 'Saturday',
                'saturday_hours' => '10:00 AM - 4:00 PM EST',
                'sunday_day' => 'Sunday',
                'sunday_status' => 'Closed',
            ],
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // Office Location
        ContactPageContent::create([
            'section' => 'office_location',
            'content' => [
                'address_line1' => '123 Charity Lane',
                'address_line2' => 'Suite 456',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
                'country' => 'United States',
            ],
            'sort_order' => 0,
            'is_active' => true,
        ]);

        // CTA Section
        ContactPageContent::create([
            'section' => 'cta',
            'content' => [
                'title' => "Ready to Make a Difference?",
                'description' => "Join thousands of supporters making an impact. Start your journey today and help create positive change in our communities.",
                'button1_text' => 'Start Donating',
                'button1_link' => '/donate',
                'button2_text' => 'Browse Organizations',
                'button2_link' => '/organizations',
            ],
            'sort_order' => 0,
            'is_active' => true,
        ]);

        $this->command->info('Contact page content seeded successfully!');
    }
}
