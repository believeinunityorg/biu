<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\TaxComplianceService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxComplianceServiceNonEinTest extends TestCase
{
    #[Test]
    public function non_ein_organizations_are_not_compliance_locked(): void
    {
        $organization = new Organization([
            'has_ein' => false,
            'ein' => '912345678',
            'tax_period' => null,
        ]);

        $result = app(TaxComplianceService::class)->evaluateForOrganization($organization);

        $this->assertFalse($result['should_lock']);
        $this->assertSame('not_applicable', $result['status']);
    }

    #[Test]
    public function real_ein_organizations_with_missing_tax_period_remain_locked(): void
    {
        $organization = new Organization([
            'has_ein' => true,
            'ein' => '123456789',
            'tax_period' => null,
        ]);

        $result = app(TaxComplianceService::class)->evaluateForOrganization($organization);

        $this->assertTrue($result['should_lock']);
        $this->assertSame('missing', $result['status']);
    }
}
