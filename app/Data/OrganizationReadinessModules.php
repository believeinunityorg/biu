<?php

namespace App\Data;

/**
 * Organization readiness dashboard modules (config-driven grouping).
 */
class OrganizationReadinessModules
{
    public const ORGANIZATION = 'organization';

    public const GOVERNANCE = 'governance';

    public const INTEGRATIONS = 'integrations';

    public const PLATFORM_SERVICES = 'platform_services';

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     description: string,
     *     sort: int
     * }>
     */
    public static function all(): array
    {
        return [
            [
                'id' => self::ORGANIZATION,
                'label' => 'Organization',
                'description' => 'Set up your organization\'s basic information and team.',
                'sort' => 0,
            ],
            [
                'id' => self::GOVERNANCE,
                'label' => 'Governance',
                'description' => 'Complete legal, financial, and compliance requirements.',
                'sort' => 1,
            ],
            [
                'id' => self::INTEGRATIONS,
                'label' => 'Integrations',
                'description' => 'Connect your third-party services.',
                'sort' => 2,
            ],
            [
                'id' => self::PLATFORM_SERVICES,
                'label' => 'Platform Services',
                'description' => 'Configure the services your organization will use.',
                'sort' => 3,
            ],
        ];
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $module) {
            if ($module['id'] === $id) {
                return $module;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_column(self::all(), 'id');
    }
}
