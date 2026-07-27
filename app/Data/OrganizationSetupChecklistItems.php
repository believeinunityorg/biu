<?php

namespace App\Data;

/**
 * Organization setup checklist — config-driven readiness items grouped by module.
 *
 * @phpstan-type ChecklistItemDefinition array{
 *     id: string,
 *     module: string,
 *     section: string,
 *     label: string,
 *     description: string,
 *     route: string,
 *     route_label: string,
 *     anchor: string|null,
 *     weight: int
 * }
 */
class OrganizationSetupChecklistItems
{
    /** @deprecated Use OrganizationReadinessModules constants */
    public const SECTION_SYSTEM = 'system';

    /** @deprecated Use OrganizationReadinessModules constants */
    public const SECTION_TOOLS = 'tools';

    /** Temporarily hidden from the setup checklist UI (re-enable by removing ids here). */
    private const HIDDEN_ITEM_IDS = [];

    /**
     * @return list<ChecklistItemDefinition>
     */
    public static function all(): array
    {
        return array_values(array_filter(
            self::definitions(),
            static fn (array $item): bool => ! in_array($item['id'], self::HIDDEN_ITEM_IDS, true)
        ));
    }

    /**
     * @return list<ChecklistItemDefinition>
     */
    private static function definitions(): array
    {
        return [
            // —— Organization ——
            [
                'id' => 'profile_information',
                'module' => OrganizationReadinessModules::ORGANIZATION,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Profile information',
                'description' => 'Add your organization logo, mission, and public profile details.',
                'route' => 'profile.edit',
                'route_label' => 'Edit profile',
                'anchor' => 'readiness-profile-information',
                'weight' => 1,
            ],
            [
                'id' => 'team_members',
                'module' => OrganizationReadinessModules::ORGANIZATION,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Team members',
                'description' => 'Add your board roster so your organization meets governance requirements.',
                'route' => 'governance.onboarding.index',
                'route_label' => 'Add members',
                'anchor' => 'board_member_list',
                'weight' => 1,
            ],
            [
                'id' => 'email_invites',
                'module' => OrganizationReadinessModules::ORGANIZATION,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Email invites',
                'description' => 'Connect Gmail or Outlook to send email invites to your team and supporters.',
                'route' => 'email-invite.index',
                'route_label' => 'Connect',
                'anchor' => null,
                'weight' => 1,
            ],

            // —— Governance ——
            [
                'id' => 'organization_verification',
                'module' => OrganizationReadinessModules::GOVERNANCE,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Organization verification',
                'description' => 'Verify your organization details and upload required governance documents.',
                'route' => 'governance.onboarding.index',
                'route_label' => 'Start',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'payout_settings',
                'module' => OrganizationReadinessModules::GOVERNANCE,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Payout settings',
                'description' => 'Choose Stripe Connect or PayPal for Marketplace, Merchant Hub, Courses, and Events payouts.',
                'route' => 'integrations.payout-settings',
                'route_label' => 'Configure',
                'anchor' => 'readiness-payout-settings',
                'weight' => 1,
            ],
            [
                'id' => 'gift_card_agreement',
                'module' => OrganizationReadinessModules::GOVERNANCE,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Gift Card Agreement',
                'description' => 'Review and sign the Gift Card Merchant Agreement to sell digital gift cards.',
                'route' => 'gift-cards.created',
                'route_label' => 'Review & Sign',
                'anchor' => 'gift_card_terms',
                'weight' => 1,
            ],
            [
                'id' => 'marketplace_agreement',
                'module' => OrganizationReadinessModules::GOVERNANCE,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Marketplace Agreement',
                'description' => 'Review and accept the Marketplace Seller Agreement to list and sell in the marketplace.',
                'route' => 'marketplace.product-pool.index',
                'route_label' => 'Review & Sign',
                'anchor' => null,
                'weight' => 1,
            ],

            // —— Integrations ——
            [
                'id' => 'integrations',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Integrations overview',
                'description' => 'Connect and manage your third-party integrations.',
                'route' => 'integrations.payout-settings',
                'route_label' => 'Manage',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'social_media',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Facebook',
                'description' => 'Connect your Facebook Page to publish and manage posts.',
                'route' => 'facebook.connect',
                'route_label' => 'Connect',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'youtube',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'YouTube',
                'description' => 'Connect your YouTube channel.',
                'route' => 'integrations.youtube',
                'route_label' => 'Connect',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'dropbox',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Dropbox (recordings)',
                'description' => 'Connect Dropbox to store and manage recordings.',
                'route' => 'integrations.dropbox',
                'route_label' => 'Connect',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'stripe_payouts',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'Stripe payouts (donations)',
                'description' => 'Set up Stripe to receive donations and payouts.',
                'route' => 'integrations.stripe-connect',
                'route_label' => 'Continue',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'paypal_payouts',
                'module' => OrganizationReadinessModules::INTEGRATIONS,
                'section' => self::SECTION_SYSTEM,
                'label' => 'PayPal payouts',
                'description' => 'Connect PayPal Business to receive payouts for selling modules.',
                'route' => 'integrations.paypal-payouts',
                'route_label' => 'Connect',
                'anchor' => null,
                'weight' => 1,
            ],

            // —— Platform Services ——
            [
                'id' => 'ai_chat',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'AI (ChatGPT assistant)',
                'description' => 'Access AI assistance to help with content and tasks.',
                'route' => 'ai-chat.index',
                'route_label' => 'Open',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'pay_as_you_go',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Pay-As-You-Go services',
                'description' => 'Re-up email, SMS, and AI token balances as you grow.',
                'route' => 'pay-as-you-go.index',
                'route_label' => 'Re-Up',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'overlay_studio',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Unity Live Overlay Studio',
                'description' => 'Create and manage your live overlays.',
                'route' => 'organization.livestreams.overlay-studio',
                'route_label' => 'Open',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'livestream',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Livestream',
                'description' => 'Set up your livestream events and preferences.',
                'route' => 'organization.livestreams.index',
                'route_label' => 'Continue',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'unity_live',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Unity Live',
                'description' => 'Go live directly on Unity Live.',
                'route' => 'livestreams.supporter.live',
                'route_label' => 'Start',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'unity_meet',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Unity Meet',
                'description' => 'Host and manage virtual meetings.',
                'route' => 'livestreams.supporter.index',
                'route_label' => 'Start',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'ai_video_studio',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'AI Video Studio',
                'description' => 'Generate short-form video with AI credits.',
                'route' => 'ai-media-studio.index',
                'route_label' => 'Open',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'engagement',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Engagement (email & SMS)',
                'description' => 'Create newsletters and reach supporters by email or text.',
                'route' => 'newsletter.index',
                'route_label' => 'Open',
                'anchor' => null,
                'weight' => 1,
            ],
            [
                'id' => 'auto_drip_campaign',
                'module' => OrganizationReadinessModules::PLATFORM_SERVICES,
                'section' => self::SECTION_TOOLS,
                'label' => 'Auto Drip Campaign',
                'description' => 'Automate recurring email and social campaigns.',
                'route' => 'campaigns.index',
                'route_label' => 'Create',
                'anchor' => null,
                'weight' => 1,
            ],
        ];
    }

    /**
     * @return ChecklistItemDefinition|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }
}
