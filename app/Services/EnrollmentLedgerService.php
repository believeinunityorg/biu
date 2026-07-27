<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Services\CourseEnrollmentFeeService;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ConnectionHubType;
use App\Support\CourseEnrollmentCheckoutItems;
use App\Support\UnifiedLedgerBrpActivity;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Connection Hub course / event enrollments → admin unified transaction ledger.
 */
final class EnrollmentLedgerService
{
    /**
     * @return array{organization_id: int|null, organization_name: string|null, organization_ein: string|null}
     */
    public static function organizationContext(Course $course): array
    {
        $course->loadMissing('organization.organization');
        $org = $course->organization?->organization;
        if ($org === null && $course->organization_id) {
            $org = Organization::query()->where('user_id', $course->organization_id)->first();
        }

        return [
            'organization_id' => $org?->id !== null ? (int) $org->id : null,
            'organization_name' => $org?->name !== null ? (string) $org->name : null,
            'organization_ein' => $org?->ein !== null && trim((string) $org->ein) !== ''
                ? trim((string) $org->ein)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function metaFor(Course $course, Enrollment $enrollment): array
    {
        $orgCtx = self::organizationContext($course);
        $hubType = (string) ($course->type ?? '');
        $isEvent = ConnectionHubType::usesEventSemantics($hubType);
        $courseName = (string) $course->name;
        $isBelievePoints = (string) ($enrollment->payment_method ?? '') === 'believe_points';

        return array_filter([
            'source' => 'course_enrollment',
            'ledger_type' => $isBelievePoints ? UnifiedLedgerType::BP : UnifiedLedgerType::MONEY,
            'connection_hub_type' => in_array($hubType, ConnectionHubType::VALUES, true) ? $hubType : null,
            'course_id' => $course->id,
            'course_name' => $courseName,
            'course_type' => $course->type,
            'event_name' => $isEvent ? $courseName : null,
            'enrollment_id' => $enrollment->enrollment_id,
            'enrollment_record_id' => $enrollment->id,
            'pricing_type' => $course->pricing_type,
            'enrollment_billing_cycle' => $course->enrollment_billing_cycle,
            'organization_id' => $orgCtx['organization_id'],
            'organization_name' => $orgCtx['organization_name'],
            'description' => ($isEvent ? 'Event registration: ' : 'Course enrollment: ').$courseName,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Sale base locked on the enrollment record — not the course's current list price.
     */
    public static function resolvedSaleAmount(Enrollment $enrollment, Course $course): float
    {
        if (($course->pricing_type ?? '') === 'free' || (string) ($enrollment->payment_method ?? '') === 'free') {
            return 0.0;
        }

        if ((string) ($enrollment->payment_method ?? '') === 'believe_points') {
            return CourseEnrollmentFeeService::listingBaseFromEnrollment($course, $enrollment);
        }

        if ($enrollment->amount_paid !== null && $enrollment->amount_paid !== '') {
            return round(max(0, (float) $enrollment->amount_paid), 2);
        }

        return round(max(0, (float) ($course->course_fee ?? 0)), 2);
    }

    public static function syncTransaction(Transaction $transaction, Enrollment $enrollment, ?Course $course = null): void
    {
        $course ??= $enrollment->course;
        if ($course === null) {
            return;
        }

        $saleAmount = self::resolvedSaleAmount($enrollment, $course);
        $meta = array_merge(
            is_array($transaction->meta) ? $transaction->meta : [],
            self::metaFor($course, $enrollment),
        );
        if ($saleAmount > 0) {
            $meta = array_merge($meta, BiuPlatformFeeService::connectionHubLedgerMetaSlice($course, $saleAmount));
        }

        $isBelievePoints = (string) ($enrollment->payment_method ?? '') === 'believe_points';

        $updates = [
            'related_id' => $enrollment->id,
            'related_type' => Enrollment::class,
            'ledger_type' => $isBelievePoints ? UnifiedLedgerType::BP : UnifiedLedgerType::MONEY,
            'bp_status' => $isBelievePoints ? UnifiedLedgerBpStatus::AVAILABLE : UnifiedLedgerBpStatus::NA,
            'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
            'amount' => $saleAmount,
            'meta' => $meta,
        ];

        if (! in_array($transaction->type, ['refund', 'cancellation'], true)) {
            $updates['type'] = 'enrollment';
        }

        $reference = trim((string) ($enrollment->transaction_id ?? ''));
        if ($reference !== ''
            && ! str_starts_with($reference, 'free_enrollment_')
            && ! str_starts_with($reference, 'believe_points_enrollment_')) {
            $updates['transaction_id'] = $reference;
        }

        $transaction->update($updates);
    }

    /**
     * Primary payment row for an enrollment (excludes refund / cancellation ledger lines).
     */
    public static function findPrimaryLedgerTransaction(Enrollment $enrollment): ?Transaction
    {
        return self::queryPrimaryCandidates($enrollment)
            ->orderByDesc('id')
            ->get()
            ->first(fn (Transaction $tx) => self::transactionBelongsToEnrollment($tx, $enrollment));
    }

    /**
     * Upsert the admin ledger row for a course/event enrollment (creates missing rows).
     *
     * @return array{transaction: Transaction|null, created: bool}
     */
    public static function syncAdminLedgerRow(Enrollment $enrollment): array
    {
        $enrollment->loadMissing('course.organization.organization', 'user');
        $course = $enrollment->course;
        $user = $enrollment->user;
        if ($course === null || $user === null) {
            return ['transaction' => null, 'created' => false];
        }

        if (! self::shouldHaveLedgerRow($enrollment, $course)) {
            return [
                'transaction' => self::findPrimaryLedgerTransaction($enrollment),
                'created' => false,
            ];
        }

        $transaction = self::findPrimaryLedgerTransaction($enrollment);
        if ($transaction !== null) {
            self::completePendingLedgerRowIfPaid($transaction, $enrollment);
            self::syncTransaction($transaction->fresh(), $enrollment, $course);
            self::pruneDuplicatePrimaryRows($enrollment, (int) $transaction->fresh()->id);

            return ['transaction' => $transaction->fresh(), 'created' => false];
        }

        $attributes = self::buildPrimaryLedgerAttributes($enrollment, $course, $user);
        if ($attributes === null) {
            return ['transaction' => null, 'created' => false];
        }

        $transaction = Transaction::query()->create($attributes);
        self::syncTransaction($transaction, $enrollment, $course);
        self::pruneDuplicatePrimaryRows($enrollment, (int) $transaction->fresh()->id);

        return ['transaction' => $transaction->fresh(), 'created' => true];
    }

    public static function relatedTypeIsEnrollment(Transaction $transaction): bool
    {
        $rt = $transaction->related_type ? ltrim((string) $transaction->related_type, '\\') : '';

        return $rt === Enrollment::class || str_ends_with($rt, 'Enrollment');
    }

    public static function transactionBelongsToEnrollment(Transaction $transaction, Enrollment $enrollment): bool
    {
        if (self::relatedTypeIsEnrollment($transaction) && (int) $transaction->related_id > 0) {
            return (int) $transaction->related_id === (int) $enrollment->id;
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        if ((int) ($meta['enrollment_record_id'] ?? 0) === (int) $enrollment->id) {
            return true;
        }

        $metaEnrollmentId = trim((string) ($meta['enrollment_id'] ?? ''));
        if ($metaEnrollmentId === '') {
            return false;
        }

        if ($metaEnrollmentId === (string) $enrollment->id) {
            return true;
        }

        $publicId = trim((string) ($enrollment->enrollment_id ?? ''));

        return $publicId !== '' && $metaEnrollmentId === $publicId;
    }

    /**
     * Resolve enrollment for a ledger row (polymorphic link, meta record id, or Stripe metadata).
     */
    public static function resolveEnrollmentForLedgerTransaction(Transaction $transaction): ?Enrollment
    {
        if (self::relatedTypeIsEnrollment($transaction) && (int) $transaction->related_id > 0) {
            $enrollment = Enrollment::query()
                ->with('course.organization.organization')
                ->find((int) $transaction->related_id);
            if ($enrollment !== null) {
                return $enrollment;
            }
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];
        $dbId = (int) ($meta['enrollment_record_id'] ?? 0);
        if ($dbId < 1 && ! empty($meta['enrollment_id']) && ctype_digit((string) $meta['enrollment_id'])) {
            $dbId = (int) $meta['enrollment_id'];
        }

        if ($dbId < 1) {
            return null;
        }

        return Enrollment::query()
            ->with('course.organization.organization')
            ->find($dbId);
    }

    private static function queryPrimaryCandidates(Enrollment $enrollment): Builder
    {
        $publicId = trim((string) ($enrollment->enrollment_id ?? ''));

        return Transaction::query()
            ->where(function (Builder $q) use ($enrollment, $publicId) {
                $q->where(function (Builder $inner) use ($enrollment) {
                    $inner->where(function (Builder $typeQ) {
                        $typeQ->where('related_type', Enrollment::class)
                            ->orWhere('related_type', 'like', '%Enrollment');
                    })->where('related_id', $enrollment->id);
                })
                    ->orWhere('meta->enrollment_record_id', $enrollment->id);

                if ($publicId !== '') {
                    $q->orWhere(function (Builder $pub) use ($publicId) {
                        $pub->where('meta->source', 'course_enrollment')
                            ->where('meta->enrollment_id', $publicId);
                    });
                }

                $q->orWhere(function (Builder $stripeMeta) use ($enrollment) {
                    $stripeMeta->where('meta->source', 'course_enrollment')
                        ->where('meta->enrollment_id', (string) $enrollment->id);
                });
            })
            ->whereNotIn('type', ['refund', 'cancellation'])
            ->where('status', '!=', Transaction::STATUS_CANCELLED);
    }

    private static function pruneDuplicatePrimaryRows(Enrollment $enrollment, int $keepId): void
    {
        if ($keepId < 1) {
            return;
        }

        $publicId = trim((string) ($enrollment->enrollment_id ?? ''));

        Transaction::query()
            ->where('id', '!=', $keepId)
            ->whereNotIn('type', ['refund', 'cancellation'])
            ->where(function (Builder $q) use ($enrollment, $publicId) {
                $q->where(function (Builder $inner) use ($enrollment) {
                    $inner->where(function (Builder $typeQ) {
                        $typeQ->where('related_type', Enrollment::class)
                            ->orWhere('related_type', 'like', '%Enrollment');
                    })->where('related_id', $enrollment->id);
                })
                    ->orWhere('meta->enrollment_record_id', $enrollment->id);

                if ($publicId !== '') {
                    $q->orWhere('meta->enrollment_id', $publicId);
                }

                $q->orWhere('meta->enrollment_id', (string) $enrollment->id);
            })
            ->delete();
    }

    private static function shouldHaveLedgerRow(Enrollment $enrollment, Course $course): bool
    {
        if (in_array($enrollment->status, ['active', 'completed', 'refunded', 'cancelled'], true)) {
            return true;
        }

        if ($enrollment->status === 'pending') {
            if (($course->pricing_type ?? '') === 'free') {
                return true;
            }

            return in_array((string) ($enrollment->payment_method ?? ''), ['stripe', 'card', 'believe_points', 'free', ''], true);
        }

        return false;
    }

    private static function completePendingLedgerRowIfPaid(Transaction $transaction, Enrollment $enrollment): void
    {
        if ($transaction->status !== Transaction::STATUS_PENDING) {
            return;
        }

        if (! in_array($enrollment->status, ['active', 'completed'], true)) {
            return;
        }

        $updates = [
            'status' => Transaction::STATUS_COMPLETED,
            'processed_at' => $enrollment->enrolled_at ?? now(),
        ];

        $reference = self::resolveExternalPaymentReference($enrollment);
        if ($reference !== null) {
            $updates['transaction_id'] = $reference;
        }

        $transaction->update($updates);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildPrimaryLedgerAttributes(Enrollment $enrollment, Course $course, User $user): ?array
    {
        $isFree = ($course->pricing_type ?? '') === 'free'
            || (string) ($enrollment->payment_method ?? '') === 'free';
        $isBelievePoints = (string) ($enrollment->payment_method ?? '') === 'believe_points';
        $saleAmount = self::resolvedSaleAmount($enrollment, $course);
        $baseMeta = self::metaFor($course, $enrollment);
        $processedAt = $enrollment->enrolled_at ?? now();

        if ($isFree) {
            return [
                'user_id' => $user->id,
                'related_id' => $enrollment->id,
                'related_type' => Enrollment::class,
                'type' => 'enrollment',
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => 0,
                'fee' => 0,
                'currency' => 'USD',
                'payment_method' => 'free',
                'meta' => $baseMeta,
                'processed_at' => $processedAt,
            ];
        }

        if ($isBelievePoints) {
            return [
                'user_id' => $user->id,
                'related_id' => $enrollment->id,
                'related_type' => Enrollment::class,
                'type' => 'enrollment',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                'brp_activity_type' => UnifiedLedgerBrpActivity::NA,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => $saleAmount,
                'fee' => 0,
                'currency' => 'USD',
                'payment_method' => 'believe_points',
                'meta' => array_merge(
                    $baseMeta,
                    [
                        'pricing_type' => 'paid',
                        'believe_points_used' => $saleAmount,
                        'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                    ],
                    BiuPlatformFeeService::connectionHubLedgerMetaSlice($course, $saleAmount)
                ),
                'processed_at' => $processedAt,
            ];
        }

        $isPaid = in_array($enrollment->status, ['active', 'completed'], true);

        return [
            'user_id' => $user->id,
            'related_id' => $enrollment->id,
            'related_type' => Enrollment::class,
            'type' => 'enrollment',
            'status' => $isPaid ? Transaction::STATUS_COMPLETED : Transaction::STATUS_PENDING,
            'amount' => $saleAmount,
            'fee' => 0,
            'currency' => 'USD',
            'payment_method' => $enrollment->payment_method ?: 'stripe',
            'transaction_id' => self::resolveExternalPaymentReference($enrollment),
            'meta' => array_merge(
                $baseMeta,
                ['pricing_type' => 'paid'],
                CourseEnrollmentCheckoutItems::stripeMetadataSlice($course),
                BiuPlatformFeeService::connectionHubLedgerMetaSlice($course, $saleAmount)
            ),
            'processed_at' => $isPaid ? $processedAt : null,
        ];
    }

    private static function resolveExternalPaymentReference(Enrollment $enrollment): ?string
    {
        foreach ([$enrollment->transaction_id, $enrollment->payment_intent_id] as $ref) {
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            if (str_starts_with($ref, 'free_enrollment_') || str_starts_with($ref, 'believe_points_enrollment_')) {
                continue;
            }

            return $ref;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $financials
     * @return array<string, mixed>
     */
    public static function mergeLedgerFinancials(Enrollment $enrollment, Transaction $t, array $financials): array
    {
        $enrollment->loadMissing('course.organization.organization');
        $course = $enrollment->course;
        if ($course === null) {
            return $financials;
        }

        $orgCtx = self::organizationContext($course);
        $gross = self::resolvedSaleAmount($enrollment, $course);
        if ($gross <= 0) {
            $gross = round(max(0, (float) $t->amount), 2);
        }
        if ($gross > 0) {
            $financials['gross_amount'] = $gross;
        }

        $meta = is_array($t->meta) ? $t->meta : [];
        $stripeFee = (float) ($financials['stripe_fee'] ?? $t->fee ?? 0);
        $biuFee = (float) ($meta['biu_platform_fee'] ?? $meta['platform_fee'] ?? 0);
        if ($biuFee > 0) {
            $financials['biu_fee'] = round($biuFee, 2);
            $financials['platform_payout'] = round($biuFee, 2);
        }

        if (! empty($orgCtx['organization_name'])) {
            $financials['organization_name'] = $orgCtx['organization_name'];
        }

        $orgNet = round(max(0, $gross - $biuFee - $stripeFee), 2);
        if ($orgNet > 0) {
            $financials['organization_payout'] = $orgNet;
            $financials['net_to_organization'] = $orgNet;
        }

        $creator = $course->creator;
        if ($creator !== null && filled($creator->name)) {
            $financials['supplier_name'] = (string) $creator->name;
            $financials['supplier_type'] = 'SUPPORTER';
        }

        return $financials;
    }

    public static function transactionIsEnrollment(Transaction $transaction): bool
    {
        if (self::relatedTypeIsEnrollment($transaction)) {
            return true;
        }

        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        return ($meta['source'] ?? '') === 'course_enrollment'
            || ! empty($meta['enrollment_record_id']);
    }

    /**
     * Mark enrollment checkout paid (Stripe webhook or success URL). Idempotent for enrolled count.
     *
     * @param  array<string, string>  $sessionMeta
     * @param  array<string, mixed>  $extraMeta
     */
    public static function completeFromStripeCheckout(
        Transaction $transaction,
        string $paymentIntentId,
        array $sessionMeta = [],
        array $extraMeta = [],
    ): void {
        if (! self::transactionIsEnrollment($transaction)) {
            return;
        }

        $enrollment = self::resolveEnrollmentForTransaction($transaction, $sessionMeta);
        if ($enrollment === null || $enrollment->course === null) {
            return;
        }

        $wasPendingEnrollment = in_array($enrollment->status, ['pending', 'failed'], true);
        $wasPendingTransaction = $transaction->status === Transaction::STATUS_PENDING;
        $existingMeta = is_array($transaction->meta) ? $transaction->meta : [];

        $transactionUpdates = [
            'meta' => array_merge($existingMeta, $extraMeta),
        ];
        if ($wasPendingTransaction) {
            $transactionUpdates['status'] = Transaction::STATUS_COMPLETED;
            $transactionUpdates['processed_at'] = now();
        }
        if ($paymentIntentId !== '') {
            $transactionUpdates['transaction_id'] = $paymentIntentId;
        }

        $transaction->update($transactionUpdates);
        self::syncTransaction($transaction->fresh(), $enrollment->fresh(), $enrollment->course);

        if ($wasPendingEnrollment) {
            $enrollment->update([
                'status' => 'active',
                'transaction_id' => $paymentIntentId !== '' ? $paymentIntentId : $enrollment->transaction_id,
                'payment_method' => $enrollment->payment_method ?: 'card',
                'enrolled_at' => $enrollment->enrolled_at ?? now(),
            ]);
            $enrollment->course->increment('enrolled');
        }
    }

    /**
     * @param  array<string, string>  $sessionMeta
     */
    public static function resolveEnrollmentForTransaction(Transaction $transaction, array $sessionMeta = []): ?Enrollment
    {
        $dbId = null;
        if (! empty($sessionMeta['enrollment_id']) && ctype_digit((string) $sessionMeta['enrollment_id'])) {
            $dbId = (int) $sessionMeta['enrollment_id'];
        } elseif ($transaction->related_id !== null && (int) $transaction->related_id > 0) {
            $dbId = (int) $transaction->related_id;
        }

        if ($dbId === null || $dbId < 1) {
            return null;
        }

        return Enrollment::query()
            ->with('course.organization.organization')
            ->find($dbId);
    }
}
