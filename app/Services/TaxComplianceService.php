<?php

namespace App\Services;

use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TaxComplianceService
{
    /**
     * Evaluate tax compliance for an organization.
     * Non-EIN (placeholder EIN) registrations are not locked by missing IRS tax periods.
     */
    public function evaluateForOrganization(Organization $organization, int $expirationMonths = 36): array
    {
        if (! $organization->isSubjectToIrsTaxCompliance()) {
            $checkedAt = Carbon::now();

            return [
                'status' => 'not_applicable',
                'normalized_tax_period' => null,
                'parsed_tax_period_date' => null,
                'months_since_tax_period' => null,
                'is_expired' => false,
                'checked_at' => $checkedAt,
                'meta' => [
                    'status' => 'not_applicable',
                    'reason' => 'organization_registered_without_ein',
                    'checked_at' => $checkedAt->toIso8601String(),
                    'expiration_threshold_months' => $expirationMonths,
                ],
                'should_lock' => false,
            ];
        }

        return $this->evaluate($organization->tax_period, $organization->ein, $expirationMonths);
    }

    public function evaluate(?string $taxPeriod, ?string $ein = null, int $expirationMonths = 36): array
    {
        $checkedAt = Carbon::now();

        $context = [
            'ein' => $ein,
            'raw_tax_period' => $taxPeriod,
            'checked_at' => $checkedAt->toDateTimeString(),
            'expiration_threshold_months' => $expirationMonths,
        ];

        $result = [
            'status' => 'missing',
            'normalized_tax_period' => null,
            'parsed_tax_period_date' => null,
            'months_since_tax_period' => null,
            'is_expired' => true,
            'checked_at' => $checkedAt,
            'meta' => [],
            'should_lock' => true,
        ];

        if (empty($taxPeriod)) {
            Log::info('Organization tax compliance check: tax period missing', $context);

            $result['meta'] = [
                'status' => 'missing',
                'reason' => 'tax_period_missing',
                'checked_at' => $checkedAt->toIso8601String(),
                'expiration_threshold_months' => $expirationMonths,
            ];

            return $result;
        }

        $normalized = preg_replace('/[^0-9]/', '', $taxPeriod);

        if (strlen($normalized) !== 6) {
            Log::warning('Organization tax compliance check: invalid tax period format', array_merge($context, [
                'normalized_tax_period' => $normalized,
            ]));

            $result['status'] = 'invalid';
            $result['normalized_tax_period'] = $normalized;
            $result['meta'] = [
                'status' => 'invalid',
                'reason' => 'invalid_format',
                'normalized_tax_period' => $normalized,
                'checked_at' => $checkedAt->toIso8601String(),
                'expiration_threshold_months' => $expirationMonths,
            ];

            return $result;
        }

        try {
            $periodDate = Carbon::createFromFormat('Ym', $normalized)->endOfMonth();
        } catch (\Exception $exception) {
            Log::error('Organization tax compliance check: unable to parse tax period', array_merge($context, [
                'normalized_tax_period' => $normalized,
                'error' => $exception->getMessage(),
            ]));

            $result['status'] = 'invalid';
            $result['normalized_tax_period'] = $normalized;
            $result['meta'] = [
                'status' => 'invalid',
                'reason' => 'parse_failure',
                'normalized_tax_period' => $normalized,
                'error' => $exception->getMessage(),
                'checked_at' => $checkedAt->toIso8601String(),
                'expiration_threshold_months' => $expirationMonths,
            ];

            return $result;
        }

        $monthsSince = $periodDate->diffInMonths($checkedAt);
        $isExpired = $monthsSince >= $expirationMonths;
        $status = $isExpired ? 'expired' : 'current';
        $shouldLock = $isExpired;

        $logPayload = array_merge($context, [
            'normalized_tax_period' => $normalized,
            'parsed_tax_period_date' => $periodDate->toDateString(),
            'months_since_tax_period' => $monthsSince,
            'is_tax_period_expired' => $isExpired,
        ]);

        Log::info('Organization tax compliance check: evaluated', $logPayload);

        return [
            'status' => $status,
            'normalized_tax_period' => $normalized,
            'parsed_tax_period_date' => $periodDate,
            'months_since_tax_period' => $monthsSince,
            'is_expired' => $isExpired,
            'checked_at' => $checkedAt,
            'meta' => [
                'status' => $status,
                'normalized_tax_period' => $normalized,
                'parsed_tax_period_date' => $periodDate->toDateString(),
                'months_since_tax_period' => $monthsSince,
                'checked_at' => $checkedAt->toIso8601String(),
                'expiration_threshold_months' => $expirationMonths,
            ],
            'should_lock' => $shouldLock,
        ];
    }
}

