<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Transaction;
use App\Services\BiuPlatformFeeService;
use App\Services\ConnectionHubEnrollmentService;
use App\Services\CourseEnrollmentFeeService;
use App\Services\EnrollmentLedgerService;
use App\Support\CourseEnrollmentCheckoutItems;
use App\Support\EnrollmentNotificationVia;
use App\Support\StripeAutomaticTax;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;

class EnrollmentController extends Controller
{
    /**
     * Show the enrollment page
     */
    public function show($slug)
    {
        $course = Course::where('slug', $slug)
            ->with(['topic', 'eventType', 'organization', 'creator'])
            ->firstOrFail();

        $user = Auth::user();

        if ($course->isCancelled()) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'This listing has been cancelled by the host.');
        }

        // Check if user is already enrolled
        $existingEnrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if ($existingEnrollment) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'You are already enrolled in this course.');
        }

        // Check if course is full
        $totalEnrolled = Enrollment::where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->count();

        if ($totalEnrolled >= $course->max_participants) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'This course is full.');
        }

        return Inertia::render('frontend/course/Enroll', [
            'course' => $course,
            'feeBreakdown' => $course->pricing_type === 'paid'
                ? CourseEnrollmentFeeService::purchaseBreakdown($course)
                : null,
        ]);
    }

    /**
     * Process the course enrollment
     */
    public function store(Request $request, $slug)
    {
        $course = Course::where('slug', $slug)->firstOrFail();

        $validated = $request->validate([
            'terms_accepted' => 'required|accepted',
        ]);

        $user = Auth::user();

        if ($course->isCancelled()) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'This listing has been cancelled by the host.');
        }

        // Check if user is already enrolled
        $existingEnrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if ($existingEnrollment) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'You are already enrolled in this course.');
        }

        // Check if course is full
        $totalEnrolled = Enrollment::where('course_id', $course->id)
            ->whereIn('status', ['active', 'completed'])
            ->count();

        if ($totalEnrolled >= $course->max_participants) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'This course is full.');
        }

        // Check if course has started (Learning / Earning / one-time listings only)
        if (ConnectionHubEnrollmentService::enrollmentBlockedByStartDate($course)) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', ConnectionHubEnrollmentService::startDateEnrollmentBlockMessage($course));
        }

        try {
            DB::beginTransaction();

            // Generate unique enrollment ID
            $enrollmentId = $this->generateUniqueEnrollmentId();

            // Create enrollment record
            $enrollment = Enrollment::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'status' => 'pending',
                'amount_paid' => $course->pricing_type === 'paid' ? $course->course_fee : 0,
                'payment_method' => $course->pricing_type === 'paid' ? 'believe_points' : 'free',
                'enrolled_at' => now(),
                'enrollment_id' => $enrollmentId,
            ]);

            if ($course->pricing_type === 'free') {
                // For free courses, complete enrollment immediately
                $enrollment->update([
                    'status' => 'active',
                    'transaction_id' => 'free_enrollment_'.$enrollment->id,
                ]);

                // Create transaction record for free enrollment
                $tx = Transaction::record([
                    'user_id' => $user->id,
                    'related_id' => $enrollment->id,
                    'related_type' => Enrollment::class,
                    'type' => 'enrollment',
                    'status' => Transaction::STATUS_COMPLETED,
                    'amount' => 0,
                    'fee' => 0,
                    'currency' => 'USD',
                    'payment_method' => 'free',
                    'meta' => EnrollmentLedgerService::metaFor($course, $enrollment),
                    'processed_at' => now(),
                ]);
                EnrollmentLedgerService::syncTransaction($tx, $enrollment, $course);

                // Update course enrolled count
                $course->increment('enrolled');

                DB::commit();

                // Redirect to success page with enrollment_id as query parameter
                return redirect(route('courses.enrollment.success').'?enrollment_id='.$enrollment->id)
                    ->with('success', 'Successfully enrolled in the course!');
            } else {
                $feeBreakdown = CourseEnrollmentFeeService::purchaseBreakdown($course);
                $listingFee = $feeBreakdown['course_fee'];
                $pointsRequired = $feeBreakdown['total_bp'];
                $platformFee = $feeBreakdown['platform_fee'];
                $user->refresh();

                if ($user->purchasedBelievePointsBalance() < $pointsRequired) {
                    DB::rollBack();
                    if (isset($enrollment)) {
                        $enrollment->update(['status' => 'failed']);
                    }

                    $have = $user->purchasedBelievePointsBalance();

                    return redirect()->route('course.show', $course->slug)
                        ->with('error', "Insufficient purchased Believe Points. You need {$pointsRequired} points but only have {$have} (Gift BP can only be used for gift cards).");
                }

                if (! $user->deductBelievePoints($pointsRequired)) {
                    DB::rollBack();
                    if (isset($enrollment)) {
                        $enrollment->update(['status' => 'failed']);
                    }

                    return redirect()->route('course.show', $course->slug)
                        ->with('error', 'Failed to deduct Believe Points. Please try again.');
                }

                $enrollment->update([
                    'status' => 'active',
                    'payment_method' => 'believe_points',
                    'amount_paid' => $pointsRequired,
                    'platform_fee_paid' => $platformFee,
                    'transaction_id' => 'believe_points_enrollment_'.$enrollment->id,
                ]);

                $tx = Transaction::record([
                    'user_id' => $user->id,
                    'related_id' => $enrollment->id,
                    'related_type' => Enrollment::class,
                    'type' => 'enrollment',
                    'status' => Transaction::STATUS_COMPLETED,
                    'amount' => $pointsRequired,
                    'fee' => 0,
                    'currency' => 'USD',
                    'payment_method' => 'believe_points',
                    'meta' => array_merge(
                        EnrollmentLedgerService::metaFor($course, $enrollment),
                        [
                            'pricing_type' => 'paid',
                            'believe_points_used' => $pointsRequired,
                            'listing_fee_bp' => $listingFee,
                            'platform_fee_paid' => $platformFee,
                            'platform_fee_not_refundable' => true,
                        ],
                        BiuPlatformFeeService::connectionHubLedgerMetaSlice($course, $listingFee)
                    ),
                    'processed_at' => now(),
                ]);
                EnrollmentLedgerService::syncTransaction($tx, $enrollment->fresh(), $course);

                $course->increment('enrolled');

                DB::commit();

                return redirect(route('courses.enrollment.success').'?enrollment_id='.$enrollment->id)
                    ->with('success', 'Successfully enrolled using Believe Points!');
            }
        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($enrollment)) {
                $enrollment->update(['status' => 'failed']);
            }

            return redirect()->back()->with([
                'error' => 'Enrollment processing failed: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Handle successful enrollment/payment
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');
        $enrollmentId = $request->get('enrollment_id');

        // Handle enrollment success with enrollment_id (no session_id) - check for free, Believe Points, or other payment methods
        if ($enrollmentId && ! $sessionId) {
            $enrollment = Enrollment::with([
                'course.organization',
                'course.topic',
                'course.eventType',
                'user',
            ])->find($enrollmentId);

            if ($enrollment) {
                // Check if it's a free enrollment
                if ($enrollment->course->pricing_type === 'free') {
                    return Inertia::render('frontend/course/enrollment/Success', [
                        'enrollment' => $enrollment,
                        'course' => $enrollment->course,
                        'type' => 'free',
                        'meetingLink' => $enrollment->course->meeting_link,
                    ]);
                }

                // Check if it's a Believe Points payment
                if ($enrollment->payment_method === 'believe_points' && $enrollment->status === 'active') {
                    // This is a Believe Points payment - show success page
                    return Inertia::render('frontend/course/enrollment/Success', [
                        'enrollment' => $enrollment,
                        'course' => $enrollment->course,
                        'type' => 'paid',
                        'paymentMethod' => 'believe_points',
                        'meetingLink' => $enrollment->course->meeting_link,
                    ]);
                }

                // If it's a paid course but not Believe Points and no session_id, it's an error
                if ($enrollment->course->pricing_type === 'paid' && $enrollment->payment_method !== 'believe_points') {
                    return redirect()->route('course.index')->with([
                        'warning' => 'Payment session missing. Please complete your payment.',
                    ]);
                }
            } else {
                // Enrollment not found
                return redirect()->route('course.index')->with([
                    'error' => 'Enrollment not found. Please contact support if you believe this is an error.',
                ]);
            }
        }

        // Handle Stripe payment success - must have session_id
        if (! $sessionId && ! $enrollmentId) {
            // No enrollment_id or session_id found
            return redirect()->route('course.index')->with([
                'warning' => 'Invalid enrollment session. Please try enrolling again.',
            ]);
        }

        try {
            DB::beginTransaction();

            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
            $enrollment = Enrollment::with([
                'course.organization',
                'course.topic',
                'course.eventType',
                'user',
            ])->findOrFail($session->metadata->enrollment_id);

            $paymentIntentId = is_string($session->payment_intent)
                ? $session->payment_intent
                : (string) ($session->payment_intent->id ?? '');

            $sessionMeta = [];
            foreach ((array) ($session->metadata ?? []) as $key => $value) {
                if ($value !== null && $value !== '') {
                    $sessionMeta[(string) $key] = (string) $value;
                }
            }

            if (isset($session->metadata->transaction_id)) {
                $transaction = Transaction::find($session->metadata->transaction_id);
                if ($transaction) {
                    $amountTotalUsd = isset($session->amount_total) ? round(((int) $session->amount_total) / 100, 2) : null;
                    $amountTaxUsd = isset($session->total_details->amount_tax)
                        ? round(((int) $session->total_details->amount_tax) / 100, 2)
                        : null;

                    EnrollmentLedgerService::completeFromStripeCheckout(
                        $transaction,
                        $paymentIntentId,
                        $sessionMeta,
                        array_merge(
                            [
                                'stripe_session_id' => $session->id,
                                'stripe_payment_intent' => $paymentIntentId,
                                'payment_status' => $session->payment_status,
                                'stripe_checkout_amount_total_usd' => $amountTotalUsd,
                                'stripe_checkout_amount_tax_usd' => $amountTaxUsd,
                            ],
                            BiuPlatformFeeService::ledgerMetaSlice(
                                EnrollmentLedgerService::resolvedSaleAmount($enrollment, $enrollment->course)
                            )
                        )
                    );
                }
            } elseif (in_array($enrollment->status, ['pending', 'failed'], true)) {
                $enrollment->update([
                    'transaction_id' => $paymentIntentId,
                    'payment_method' => $session->payment_method_types[0] ?? 'card',
                    'status' => 'active',
                    'enrolled_at' => now(),
                ]);
                $enrollment->course->increment('enrolled');
            }

            $enrollment->refresh()->load([
                'course.organization',
                'course.topic',
                'course.eventType',
                'user',
            ]);

            DB::commit();

            return Inertia::render('frontend/course/enrollment/Success', [
                'enrollment' => $enrollment,
                'course' => $enrollment->course,
                'type' => 'paid',
                'paymentMethod' => 'stripe',
                'meetingLink' => $enrollment->course->meeting_link,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->route('course.index')->withErrors([
                'message' => 'Error verifying payment: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Handle cancelled enrollment
     */
    public function cancel($enrollmentId)
    {
        $enrollment = Enrollment::with([
            'course.organization',
            'course.topic',
        ])->findOrFail($enrollmentId);

        // Update enrollment status to cancelled
        $enrollment->update(['status' => 'cancelled']);

        // Update related transaction to cancelled
        $transaction = Transaction::where('related_id', $enrollment->id)
            ->where('related_type', Enrollment::class)
            ->where('status', Transaction::STATUS_PENDING)
            ->first();

        if ($transaction) {
            $transaction->update([
                'status' => Transaction::STATUS_CANCELLED,
                'processed_at' => now(),
            ]);
        }

        return Inertia::render('frontend/course/enrollment/Cancel', [
            'enrollment' => $enrollment,
            'course' => $enrollment->course,
        ]);
    }

    /**
     * Cancel user enrollment
     */
    public function cancelEnrollment(Request $request, $slug)
    {
        $course = Course::where('slug', $slug)->firstOrFail();
        $user = Auth::user();

        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $enrollment) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'No active enrollment found.');
        }

        // Check if cancellation is allowed (24 hours before start)
        $startDateTime = $this->parseDateTime($course->start_date, $course->start_time);
        $hoursUntilStart = now()->diffInHours($startDateTime, false);

        if ($hoursUntilStart < 24) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'Cancellation is only allowed 24 hours before course start.');
        }

        try {
            DB::beginTransaction();

            $enrollment->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            // Create cancellation transaction record
            $cancelTx = Transaction::record([
                'user_id' => $user->id,
                'related_id' => $enrollment->id,
                'related_type' => Enrollment::class,
                'type' => 'cancellation',
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => 0,
                'fee' => 0,
                'currency' => 'USD',
                'payment_method' => $enrollment->payment_method,
                'meta' => array_merge(
                    EnrollmentLedgerService::metaFor($course, $enrollment),
                    [
                        'original_amount' => $enrollment->amount_paid,
                        'cancellation_reason' => 'user_requested',
                    ]
                ),
                'processed_at' => now(),
            ]);
            EnrollmentLedgerService::syncTransaction($cancelTx, $enrollment, $course);

            // Decrease course enrolled count
            $course->decrement('enrolled');

            DB::commit();

            return redirect()->route('course.show', $course->slug)
                ->with('success', 'Your enrollment has been cancelled successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors([
                'cancellation' => 'Failed to cancel enrollment: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Request refund for enrollment
     */
    public function refund(Request $request, $slug)
    {
        $course = Course::where('slug', $slug)->firstOrFail();
        $user = Auth::user();

        $enrollment = Enrollment::where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->where('amount_paid', '>', 0)
            ->first();

        if (! $enrollment) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'No eligible enrollment found for refund.');
        }

        // Check if refund is allowed (within 7 days of enrollment)
        $daysSinceEnrollment = $enrollment->enrolled_at->diffInDays(now());

        if ($daysSinceEnrollment > 7) {
            return redirect()->route('course.show', $course->slug)
                ->with('error', 'Refund requests are only allowed within 7 days of enrollment.');
        }

        try {
            DB::beginTransaction();

            // Process Stripe refund if there's a transaction ID
            if ($enrollment->transaction_id && $enrollment->transaction_id !== 'free_enrollment_'.$enrollment->id) {
                $stripe = Cashier::stripe();
                $refund = $stripe->refunds->create([
                    'payment_intent' => $enrollment->transaction_id,
                    'reason' => 'requested_by_customer',
                ]);

                // Create refund transaction record
                $refundTx = Transaction::record([
                    'user_id' => $user->id,
                    'related_id' => $enrollment->id,
                    'related_type' => Enrollment::class,
                    'type' => 'refund',
                    'status' => Transaction::STATUS_COMPLETED,
                    'amount' => $enrollment->amount_paid,
                    'fee' => 0,
                    'currency' => 'USD',
                    'payment_method' => 'stripe',
                    'transaction_id' => $refund->id,
                    'meta' => array_merge(
                        EnrollmentLedgerService::metaFor($course, $enrollment),
                        [
                            'original_payment_intent' => $enrollment->transaction_id,
                            'stripe_refund_id' => $refund->id,
                            'refund_reason' => 'requested_by_customer',
                        ]
                    ),
                    'processed_at' => now(),
                ]);
                EnrollmentLedgerService::syncTransaction($refundTx, $enrollment, $course);
            }

            $enrollment->update([
                'status' => 'refunded',
                'refunded_at' => now(),
            ]);

            // Decrease course enrolled count
            $course->decrement('enrolled');

            DB::commit();

            return redirect()->route('course.show', $course->slug)
                ->with('success', 'Your refund request has been processed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors([
                'refund' => 'Failed to process refund: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Show user's enrollments
     */
    public function myEnrollments(Request $request)
    {
        $user = Auth::user();

        $query = Enrollment::with([
            'course.topic',
            'course.eventType',
            'course.organization',
            'course.creator',
        ])
            ->where('user_id', $user->id);

        // Apply search filter
        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->whereHas('course', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        // Apply status filter
        if ($request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        $enrollments = $query->orderBy('enrolled_at', 'desc')->paginate(10);

        $enrollments->getCollection()->transform(function ($enrollment) {
            if ($enrollment->status === 'active') {
                $enrollment->course->meeting_link = $enrollment->course->meeting_link;
            }

            return $enrollment;
        });

        // Calculate enrollment statistics
        $enrollmentStats = [
            'total_enrolled' => Enrollment::where('user_id', $user->id)->count(),
            'total_spent' => Enrollment::where('user_id', $user->id)->sum('amount_paid') ?? 0,
            'active_enrollments' => Enrollment::where('user_id', $user->id)->where('status', 'active')->count(),
            'completed_enrollments' => Enrollment::where('user_id', $user->id)->where('status', 'completed')->count(),
        ];

        return Inertia::render('frontend/user-profile/my-enrollments', [
            'enrollments' => $enrollments,
            'enrollmentStats' => $enrollmentStats,
            'filters' => [
                'search' => $request->get('search', ''),
                'status' => $request->get('status', ''),
            ],
            'notificationPreferences' => [
                'enrollments_via' => EnrollmentNotificationVia::normalize(
                    $user->enrollment_notifications_via,
                    EnrollmentNotificationVia::PUSH_EMAIL
                ),
                'reminders_via' => EnrollmentNotificationVia::normalize(
                    $user->enrollment_reminders_via,
                    EnrollmentNotificationVia::PUSH
                ),
            ],
        ]);
    }

    /**
     * Update enrollment notification channel preferences for the authenticated user.
     */
    public function updateNotificationPreferences(Request $request)
    {
        $validated = $request->validate([
            'enrollments_via' => 'nullable|string|in:'.implode(',', EnrollmentNotificationVia::allowed()),
            'reminders_via' => 'nullable|string|in:'.implode(',', EnrollmentNotificationVia::allowed()),
        ]);

        $user = Auth::user();
        $updates = [];

        if (array_key_exists('enrollments_via', $validated) && $validated['enrollments_via'] !== null) {
            $updates['enrollment_notifications_via'] = EnrollmentNotificationVia::normalize(
                $validated['enrollments_via'],
                EnrollmentNotificationVia::PUSH_EMAIL
            );
        }

        if (array_key_exists('reminders_via', $validated) && $validated['reminders_via'] !== null) {
            $updates['enrollment_reminders_via'] = EnrollmentNotificationVia::normalize(
                $validated['reminders_via'],
                EnrollmentNotificationVia::PUSH
            );
        }

        if ($updates !== []) {
            $user->update($updates);
        }

        return back()->with('success', 'Notification preferences updated.');
    }

    /**
     * Generate a unique enrollment ID
     * Format: ENR-YYYYMMDD-XXXXXXXX (e.g., ENR-20250115-A1B2C3D4)
     */
    private function generateUniqueEnrollmentId(): string
    {
        $maxAttempts = 10;
        $attempt = 0;

        do {
            // Generate ID with date prefix for better uniqueness
            $datePrefix = now()->format('Ymd');
            $randomPart = strtoupper(Str::random(8));
            $enrollmentId = 'ENR-'.$datePrefix.'-'.$randomPart;

            // Check if this ID already exists
            $exists = Enrollment::where('enrollment_id', $enrollmentId)->exists();

            if (! $exists) {
                return $enrollmentId;
            }

            $attempt++;

            // If we've tried too many times, add more randomness
            if ($attempt >= $maxAttempts) {
                $randomPart = strtoupper(Str::random(12));
                $enrollmentId = 'ENR-'.$datePrefix.'-'.$randomPart.'-'.time();
                // Final check before returning
                if (! Enrollment::where('enrollment_id', $enrollmentId)->exists()) {
                    return $enrollmentId;
                }
            }
        } while ($attempt < $maxAttempts);

        // Fallback: use timestamp-based ID
        return 'ENR-'.now()->format('YmdHis').'-'.strtoupper(Str::random(8));
    }

    /**
     * Parse datetime properly handling cases where date already contains time
     */
    private function parseDateTime($date, $time)
    {
        try {
            // Handle different date formats
            if (strpos($date, ' ') !== false) {
                // Date already contains time, extract just the date part
                $datePart = explode(' ', $date)[0];
            } else {
                $datePart = $date;
            }

            // Combine date and time
            $dateTimeString = $datePart.' '.$time;

            return Carbon::parse($dateTimeString);

        } catch (\Exception $e) {
            Log::error('DateTime parsing error in EnrollmentController', [
                'date' => $date,
                'time' => $time,
                'error' => $e->getMessage(),
            ]);

            // Fallback to current time
            return Carbon::now();
        }
    }
}
