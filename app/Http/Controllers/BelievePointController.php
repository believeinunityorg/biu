<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessBelievePointsAutoReplenishJob;
use App\Jobs\RetryBelievePointPurchaseSettlementJob;
use App\Models\AdminSetting;
use App\Models\BelievePointPurchase;
use App\Models\BelievePointWalletTransfer;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BelievePointPurchaseSettlementReconciliationService;
use App\Services\BelievePointPurchaseSettlementService;
use App\Services\BelievePointPurchaseSettlementStatusService;
use App\Services\BelievePointsPaymentMethodSyncService;
use App\Services\BelievePointsPurchaseCalculationService;
use App\Services\BelievePointsPurchaseSettingsService;
use App\Services\BelievePointsToBridgeWalletService;
use App\Services\BelievePointsWalletLedgerService;
use App\Services\BelievePointsWalletTransferSettingsService;
use App\Services\Payments\BelievePointsPaymentMethodResolver;
use App\Services\Payments\BelievePointsPurchasePaymentService;
use App\Services\StripeEnvironmentSyncService;
use App\Services\UserStripePaymentMethodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

class BelievePointController extends Controller
{
    /**
     * Display the believe points purchase page.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Check if believe points are enabled
        $isEnabled = (bool) AdminSetting::get('believe_points_enabled', true);
        if (! $isEnabled) {
            return redirect()->route('dashboard')->with('error', 'Believe Points purchases are currently disabled.');
        }

        $minPurchaseAmount = (float) AdminSetting::get('believe_points_min_purchase', 10.00);
        $maxPurchaseAmount = (float) AdminSetting::get('believe_points_max_purchase', 10000.00);

        // Release any Processing BP whose hold period has ended (also runs on schedule).
        BelievePointPurchaseSettlementReconciliationService::reconcilePendingPurchasesForUser($user);
        BelievePointPurchaseSettlementService::releaseDueProcessingPoints();
        $user->refresh();

        app(BelievePointsToBridgeWalletService::class)->reconcileSubmittedTransfers((int) $user->id);

        // Get user's current believe points balance
        $currentBalance = $user->currentBelievePoints();

        // Get user's purchase history
        $purchases = BelievePointPurchase::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->through(static fn (BelievePointPurchase $purchase) => array_merge(
                $purchase->toArray(),
                BelievePointPurchaseSettlementStatusService::historyPayload($purchase),
            ));

        $walletTransfers = BelievePointWalletTransfer::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(static fn (BelievePointWalletTransfer $transfer) => [
                'id' => $transfer->id,
                'amount' => round((float) $transfer->amount, 2),
                'status' => $transfer->status,
                'bridge_transfer_state' => $transfer->bridge_transfer_state,
                'failure_message' => $transfer->failure_message,
                'created_at' => $transfer->created_at?->toIso8601String(),
                'completed_at' => $transfer->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $feePreview = null;
        if ($request->filled('fee_preview_amount')) {
            $validator = Validator::make($request->only(['fee_preview_amount', 'fee_preview_rail']), [
                'fee_preview_amount' => ['required', 'numeric', 'min:'.$minPurchaseAmount, 'max:'.$maxPurchaseAmount],
                'fee_preview_rail' => ['nullable', 'in:card,bank'],
            ]);
            if (! $validator->fails()) {
                $base = round((float) $validator->validated()['fee_preview_amount'], 2);
                $rail = $request->input('fee_preview_rail', 'bank');
                $rail = in_array($rail, ['card', 'bank'], true) ? $rail : 'bank';
                $feePreview = $this->believePointsFeePreviewPayload($base, $rail, $user);
            }
        }

        return Inertia::render('BelievePoints/Index', [
            'currentBalance' => $currentBalance,
            'processingBalance' => $user->currentProcessingBelievePoints(),
            'processingReleaseAt' => $user->nextProcessingBelievePointsReleaseAt()?->toIso8601String(),
            'processingBatches' => $user->processingBelievePointsBatches(),
            'giftedBalance' => round((float) ($user->gifted_believe_points ?? 0), 2),
            'holdingBalance' => round((float) ($user->holding_believe_points ?? 0), 2),
            'minPurchaseAmount' => $minPurchaseAmount,
            'maxPurchaseAmount' => $maxPurchaseAmount,
            'purchases' => $purchases,
            'walletTransfers' => $walletTransfers,
            'walletLedger' => app(BelievePointsWalletLedgerService::class)->paginateForUser(
                $user,
                max(1, min(100, (int) $request->integer('ledger_per_page', 25))),
                max(1, (int) $request->integer('ledger_page', 1)),
            ),
            'feePreview' => $feePreview,
            'purchaseSettings' => BelievePointsPurchaseSettingsService::frontendPayload($user),
            'availableMethods' => BelievePointsPaymentMethodResolver::availableMethods(),
            'savedPaymentMethods' => UserStripePaymentMethodService::listForUser($user),
            'paymentMethodsUrl' => $user->hasNonprofitDashboardRole()
                ? route('settings.saved-payment-methods.index')
                : route('user.profile.payment-methods.index'),
            'autoReplenish' => $this->autoReplenishPayloadForUser($user),
            'walletTransfer' => app(BelievePointsWalletTransferSettingsService::class)->frontendPayload($user),
        ]);
    }

    /**
     * Move purchased Believe Points into the user's verified Bridge wallet.
     */
    public function transferToWallet(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $settings = app(BelievePointsWalletTransferSettingsService::class);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$settings->minAmount(), 'max:'.$settings->maxAmount()],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $result = app(BelievePointsToBridgeWalletService::class)->transfer(
            $user,
            (float) $validated['amount'],
            $validated['idempotency_key'] ?? null,
        );

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Fee preview for Add Believe Points checkout (includes platform fee + BRP).
     *
     * @return array<string, mixed>
     */
    private function believePointsFeePreviewPayload(float $netPointsUsd, string $rail, ?User $user = null): array
    {
        $rail = in_array($rail, ['card', 'bank'], true) ? $rail : 'bank';

        return BelievePointsPurchaseCalculationService::feePreviewPayload(
            round(max(0, $netPointsUsd), 2),
            $rail,
            $user
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseBreakdownForRail(float $netPointsUsd, string $rail, ?User $user = null, bool $isTrustedCard = false): array
    {
        return BelievePointsPurchaseCalculationService::checkoutBreakdown(
            round(max(0, $netPointsUsd), 2),
            $rail,
            $user,
            $isTrustedCard
        );
    }

    /**
     * Create a Stripe checkout session for believe points purchase.
     */
    public function purchase(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Check if believe points are enabled
        $isEnabled = (bool) AdminSetting::get('believe_points_enabled', true);
        if (! $isEnabled) {
            return response()->json(['error' => 'Believe Points purchases are currently disabled.'], 400);
        }

        $minPurchaseAmount = (float) AdminSetting::get('believe_points_min_purchase', 10.00);
        $maxPurchaseAmount = (float) AdminSetting::get('believe_points_max_purchase', 10000.00);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:'.$minPurchaseAmount, 'max:'.$maxPurchaseAmount],
            'payment_method' => ['nullable', 'string', 'in:stripe_card,stripe_ach,paypal,cashapp,zelle,venmo,venmo_manual,cash_app_pay'],
            'payment_rail' => ['nullable', 'in:card,bank'],
            'saved_payment_method_id' => ['nullable', 'string', 'max:255'],
        ]);

        $paymentMethod = $validated['payment_method'] ?? null;
        if (! $paymentMethod) {
            $paymentMethod = ($validated['payment_rail'] ?? 'bank') === 'bank' ? 'stripe_ach' : 'stripe_card';
        }

        if (filled($validated['saved_payment_method_id'] ?? null)) {
            $validated['payment_rail'] = in_array($paymentMethod, ['stripe_ach'], true) ? 'bank' : 'card';

            return $this->purchaseWithSavedPaymentMethod($user, $validated);
        }

        if (in_array($paymentMethod, ['stripe_card', 'stripe_ach'], true)) {
            $validated['payment_rail'] = $paymentMethod === 'stripe_ach' ? 'bank' : 'card';

            return $this->purchaseWithStripeCheckout($user, $validated, $paymentMethod);
        }

        return app(BelievePointsPurchasePaymentService::class)->initiate($user, $validated, $paymentMethod);
    }

    /**
     * Create a Stripe checkout session for believe points purchase (card/bank).
     */
    private function purchaseWithStripeCheckout(User $user, array $validated, string $paymentMethod): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $minPurchaseAmount = (float) AdminSetting::get('believe_points_min_purchase', 10.00);
        $maxPurchaseAmount = (float) AdminSetting::get('believe_points_max_purchase', 10000.00);

        BelievePointsPaymentMethodResolver::assertMethodAllowed($paymentMethod);

        $netPointsUsd = round((float) $validated['amount'], 2);
        if ($netPointsUsd < $minPurchaseAmount || $netPointsUsd > $maxPurchaseAmount) {
            return redirect()->back()->withInput()->with('error', 'Invalid purchase amount.');
        }

        $feeRail = $validated['payment_rail'] === 'bank' ? 'bank' : 'card';
        $points = $netPointsUsd;
        $breakdown = $this->purchaseBreakdownForRail($netPointsUsd, $feeRail, $user);
        $checkoutTotalUsd = $breakdown['checkout_total_usd'];
        $processingFeeAddon = $breakdown['processing_fee_usd'];
        $platformFee = $breakdown['platform_fee_usd'];

        try {
            if (! StripeEnvironmentSyncService::ensureUserStripeCustomer($user)) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Could not prepare your billing profile. Please try again in a moment.');
            }
            $user->refresh();

            $purchase = BelievePointPurchase::create([
                'user_id' => $user->id,
                'amount' => $netPointsUsd,
                'checkout_total' => $checkoutTotalUsd,
                'processing_fee_estimate' => $processingFeeAddon,
                'platform_fee' => $platformFee,
                'points' => $points,
                'status' => 'pending',
                'source' => 'manual',
                'payment_rail' => $feeRail,
                'payment_method' => $paymentMethod,
                'is_trusted_instrument' => false,
            ]);

            $ledgerTransactionId = $this->ledgerTransactionIdForPurchase($purchase);
            $ledgerMeta = $ledgerTransactionId ? ['ledger_transaction_id' => (string) $ledgerTransactionId] : [];

            $amountInCents = (int) round($checkoutTotalUsd * 100);

            $checkoutOptions = [
                'success_url' => route('believe-points.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('believe-points.cancel').'?session_id={CHECKOUT_SESSION_ID}',
                'metadata' => array_merge([
                    'purchase_id' => (string) $purchase->id,
                    'user_id' => (string) $user->id,
                    'type' => 'believe_points_purchase',
                    'payment_rail' => $feeRail,
                    'payment_method' => $paymentMethod,
                    'base_points_usd' => (string) $netPointsUsd,
                    'checkout_total_usd' => (string) $checkoutTotalUsd,
                ], $ledgerMeta),
                'payment_method_types' => $feeRail === 'bank' ? ['us_bank_account'] : ['card'],
                'billing_address_collection' => 'auto',
                'payment_intent_data' => [
                    'metadata' => array_merge([
                        'purchase_id' => (string) $purchase->id,
                        'user_id' => (string) $user->id,
                        'type' => 'believe_points_purchase',
                        'base_points_usd' => (string) $netPointsUsd,
                        'checkout_total_usd' => (string) $checkoutTotalUsd,
                    ], $ledgerMeta),
                ],
            ];

            $checkout = $user->checkoutCharge(
                $amountInCents,
                "Purchase {$points} Believe Points (incl. est. processing)",
                1,
                $checkoutOptions
            );

            $purchase->update([
                'stripe_session_id' => $checkout->id,
            ]);

            return Inertia::location($checkout->url);
        } catch (\Exception $e) {
            Log::error('Believe Points purchase error: '.$e->getMessage());

            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to process purchase: '.$e->getMessage());
        }
    }

    /**
     * Manual payment confirmation page.
     */
    public function manualConfirm(Request $request, BelievePointPurchase $purchase): \Inertia\Response|RedirectResponse
    {
        $user = Auth::user();
        if (! $user || (int) $purchase->user_id !== (int) $user->id) {
            abort(403);
        }

        if ($purchase->status !== 'pending') {
            return redirect()->route('believe-points.index')
                ->with('warning', 'This purchase has already been processed.');
        }

        $method = $purchase->payment_method ?? 'cashapp';
        $instructions = BelievePointsPaymentMethodResolver::manualPaymentInstructions($method);

        return Inertia::render('BelievePoints/ManualConfirm', [
            'purchase' => [
                'id' => $purchase->id,
                'amount' => (float) $purchase->amount,
                'points' => (float) $purchase->points,
                'payment_method' => $method,
                'status' => $purchase->status,
            ],
            'instructions' => $instructions,
        ]);
    }

    public function manualConfirmSubmit(
        Request $request,
        BelievePointPurchase $purchase,
        BelievePointsPurchasePaymentService $paymentService
    ): RedirectResponse {
        $request->validate([
            'receipt' => 'nullable|image|max:5120',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('believe-points-receipts', 'public');
        }

        return $paymentService->confirmManualPayment($purchase, $request->user(), $receiptPath);
    }

    public function paypalCapture(
        Request $request,
        BelievePointPurchase $purchase,
        BelievePointsPurchasePaymentService $paymentService
    ): RedirectResponse {
        $user = Auth::user();
        if (! $user || (int) $purchase->user_id !== (int) $user->id) {
            abort(403);
        }

        if ($purchase->status === 'completed') {
            return redirect()->route('believe-points.index')
                ->with('success', "Successfully purchased {$purchase->points} Believe Points via PayPal!");
        }

        $token = $request->input('token');
        if (! $token) {
            return redirect()->route('believe-points.index')->with('error', 'PayPal payment was cancelled.');
        }

        $success = $paymentService->capturePayPalOrder($purchase, $token);

        if ($success) {
            $purchase->refresh();

            return redirect()->route('believe-points.index')
                ->with('success', "Successfully purchased {$purchase->points} Believe Points via PayPal!");
        }

        return redirect()->route('believe-points.index')
            ->with('error', 'PayPal payment could not be completed. Please try again.');
    }

    /**
     * Charge a saved Stripe payment method (card or bank) without a new Checkout session.
     */
    private function purchaseWithSavedPaymentMethod(User $user, array $validated): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $minPurchaseAmount = (float) AdminSetting::get('believe_points_min_purchase', 10.00);
        $maxPurchaseAmount = (float) AdminSetting::get('believe_points_max_purchase', 10000.00);

        $pmId = (string) $validated['saved_payment_method_id'];
        $paymentMethod = ($validated['payment_rail'] ?? 'bank') === 'bank' ? 'stripe_ach' : 'stripe_card';
        BelievePointsPaymentMethodResolver::assertMethodAllowed($paymentMethod);

        $feeRail = $validated['payment_rail'] === 'bank' ? 'bank' : 'card';
        $railFromPm = UserStripePaymentMethodService::railForPaymentMethod($pmId);

        if ($railFromPm !== $feeRail) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'The selected saved payment method does not match this payment type.');
        }

        if (! UserStripePaymentMethodService::paymentMethodBelongsToUser($user, $pmId)) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Invalid saved payment method.');
        }

        // A saved card already on file is a trusted instrument (immediate availability).
        // Bank/ACH always follows ACH settlement rules regardless.
        $isTrustedCard = $feeRail === 'card';

        $netPointsUsd = round((float) $validated['amount'], 2);
        $points = $netPointsUsd;
        $breakdown = $this->purchaseBreakdownForRail($netPointsUsd, $feeRail, $user, $isTrustedCard);
        $checkoutTotalUsd = $breakdown['checkout_total_usd'];
        $processingFeeAddon = $breakdown['processing_fee_usd'];
        $platformFee = $breakdown['platform_fee_usd'];

        try {
            if (! StripeEnvironmentSyncService::ensureUserStripeCustomer($user)) {
                return redirect()->back()
                    ->withInput()
                    ->with('error', 'Could not prepare your billing profile. Please try again.');
            }
            $user->refresh();

            BelievePointsPaymentMethodSyncService::ensurePaymentMethodBelongsToCustomer($user, $pmId);

            $purchase = BelievePointPurchase::create([
                'user_id' => $user->id,
                'amount' => $netPointsUsd,
                'checkout_total' => $checkoutTotalUsd,
                'processing_fee_estimate' => $processingFeeAddon,
                'platform_fee' => $platformFee,
                'points' => $points,
                'status' => 'pending',
                'source' => 'manual',
                'payment_rail' => $feeRail,
                'payment_method' => $paymentMethod,
                'is_trusted_instrument' => $isTrustedCard,
            ]);

            $amountInCents = (int) round($checkoutTotalUsd * 100);
            $intent = Cashier::stripe()->paymentIntents->create([
                'amount' => $amountInCents,
                'currency' => config('cashier.currency'),
                'customer' => $user->stripe_id,
                'payment_method' => $pmId,
                'confirm' => true,
                'off_session' => false,
                'return_url' => route('believe-points.complete-saved-payment', ['purchase' => $purchase->id]),
                'description' => 'Purchase '.$points.' Believe Points',
                'metadata' => [
                    'purchase_id' => (string) $purchase->id,
                    'user_id' => (string) $user->id,
                    'type' => 'believe_points_purchase',
                    'payment_rail' => $feeRail,
                    'base_points_usd' => (string) $netPointsUsd,
                    'checkout_total_usd' => (string) $checkoutTotalUsd,
                ],
            ]);

            $purchase->update([
                'stripe_payment_intent_id' => $intent->id,
            ]);

            if ($intent->status === 'succeeded') {
                BelievePointPurchaseSettlementService::settleCheckoutPurchase($purchase->id, $intent->id);
                $purchase->refresh();

                if ($purchase->status === 'completed') {
                    return redirect()->route('believe-points.index')
                        ->with('success', "Successfully purchased {$purchase->points} Believe Points!");
                }
            }

            if ($intent->status === 'requires_action') {
                return Inertia::location(route('cashier.payment', $intent->id));
            }

            if (in_array($intent->status, ['processing', 'requires_confirmation'], true)) {
                return redirect()->route('believe-points.index')
                    ->with('info', 'Your bank payment is processing. Believe Points will be credited once it completes.');
            }

            return redirect()->route('believe-points.index')
                ->with('error', 'Payment could not be completed. Please try another method.');
        } catch (ApiErrorException $e) {
            Log::error('Believe Points saved payment method purchase Stripe error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Payment failed. Please try again or use a different payment method.');
        } catch (\Throwable $e) {
            Log::error('Believe Points saved payment method purchase error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Payment failed. Please try again.');
        }
    }

    public function completeSavedPayment(Request $request, int $purchase): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $purchaseModel = BelievePointPurchase::query()
            ->where('id', $purchase)
            ->where('user_id', $user->id)
            ->first();

        if (! $purchaseModel || ! $purchaseModel->stripe_payment_intent_id) {
            return redirect()->route('believe-points.index')
                ->with('error', 'Could not find this purchase.');
        }

        try {
            $intent = Cashier::stripe()->paymentIntents->retrieve($purchaseModel->stripe_payment_intent_id);

            if ($intent->status === 'succeeded') {
                BelievePointPurchaseSettlementService::settleCheckoutPurchase($purchaseModel->id, $intent->id);
                $purchaseModel->refresh();

                if ($purchaseModel->status === 'completed') {
                    return redirect()->route('believe-points.index')
                        ->with('success', "Successfully purchased {$purchaseModel->points} Believe Points!");
                }
            }

            if (in_array($intent->status, ['processing', 'requires_confirmation'], true)) {
                return redirect()->route('believe-points.index')
                    ->with('info', 'Your payment is still processing. Points will be credited when it completes.');
            }

            return redirect()->route('believe-points.index')
                ->with('error', 'Payment was not completed.');
        } catch (\Throwable $e) {
            Log::error('Believe Points complete saved payment error', [
                'purchase_id' => $purchase,
                'message' => $e->getMessage(),
            ]);

            return redirect()->route('believe-points.index')
                ->with('error', 'Could not confirm payment status.');
        }
    }

    /**
     * Handle successful payment.
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');

        if (! $sessionId) {
            return redirect()->route('believe-points.index')->withErrors([
                'message' => 'Invalid purchase session',
            ]);
        }

        try {
            $sessionIdStr = (string) $sessionId;

            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionIdStr, [
                'expand' => ['payment_intent'],
            ]);

            $purchase = $this->resolveBelievePointPurchaseFromCheckoutSession($sessionIdStr, $session);

            if (! $purchase) {
                Log::warning('Believe Points success: could not resolve purchase', [
                    'session_id' => $sessionIdStr,
                    'user_id' => Auth::id(),
                    'metadata_purchase_id' => $session->metadata->purchase_id ?? null,
                ]);

                return redirect()->route('believe-points.index')
                    ->with('error', 'We could not match this checkout to a purchase. Please contact support with your receipt.');
            }

            if ((int) $purchase->user_id !== (int) Auth::id()) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'This purchase does not belong to your account.');
            }

            if ($purchase->status === 'completed') {
                return redirect()->route('believe-points.index')
                    ->with('success', "Successfully purchased {$purchase->points} Believe Points!");
            }

            $isCardRail = ($purchase->payment_rail ?? 'card') === 'card';
            $maxPollAttempts = $isCardRail ? 20 : 1;
            $paymentIntentId = null;
            $piStatus = '';
            $paidOrSucceeded = false;

            for ($attempt = 0; $attempt < $maxPollAttempts; $attempt++) {
                if ($attempt > 0) {
                    usleep(250000);
                    $session = Cashier::stripe()->checkout->sessions->retrieve($sessionIdStr, [
                        'expand' => ['payment_intent'],
                    ]);
                }

                $paymentIntentId = $this->checkoutSessionPaymentIntentId($session);
                $piStatus = '';

                if ($paymentIntentId) {
                    try {
                        $pi = Cashier::stripe()->paymentIntents->retrieve($paymentIntentId);
                        $piStatus = (string) ($pi->status ?? '');
                    } catch (\Throwable $e) {
                        Log::warning('Believe Points success: could not retrieve PaymentIntent', [
                            'purchase_id' => $purchase->id,
                            'payment_intent_id' => $paymentIntentId,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                $paidOrSucceeded = ($session->payment_status === 'paid')
                    || ($piStatus === 'succeeded');

                if ($paidOrSucceeded && $paymentIntentId) {
                    break;
                }

                if ($paymentIntentId && in_array($piStatus, ['canceled', 'failed'], true)) {
                    break;
                }

                if (! $isCardRail) {
                    break;
                }
            }

            if ($paidOrSucceeded && $paymentIntentId) {
                $settled = BelievePointPurchaseSettlementService::settleCheckoutPurchase(
                    $purchase->id,
                    $paymentIntentId
                );
                $purchase->refresh();

                if (! $settled && $purchase->status !== 'completed') {
                    Log::error('Believe Points success: settleCheckoutPurchase returned false', [
                        'purchase_id' => $purchase->id,
                        'payment_intent_id' => $paymentIntentId,
                        'purchase_status' => $purchase->status,
                        'session_payment_status' => $session->payment_status ?? null,
                        'pi_status' => $piStatus,
                    ]);
                }

                if ($purchase->status === 'completed') {
                    $message = "Successfully purchased {$purchase->points} Believe Points!";
                    if ((float) ($purchase->reward_points_awarded ?? 0) > 0) {
                        $rp = round((float) $purchase->reward_points_awarded, 2);
                        $message .= ' You earned '.number_format($rp, 0).' BRP (Believe Reward Points).';
                    }
                    if (! $purchase->points_released) {
                        $message .= ' Your BP and BRP stay in Processing until platform settlement completes, then become Available.';
                    }

                    return redirect()->route('believe-points.index')->with('success', $message);
                }
            }

            if ($paymentIntentId && in_array($piStatus, ['canceled', 'failed'], true)) {
                $purchase->update([
                    'status' => 'failed',
                    'failure_code' => $piStatus,
                    'failure_message' => 'Payment was canceled or failed.',
                ]);

                return redirect()->route('believe-points.index')
                    ->with('error', 'Payment was not completed. Please try again.');
            }

            $metaRail = (string) ($session->metadata->payment_rail ?? '');
            $methodTypes = $session->payment_method_types ?? [];
            $isBankCheckout = $metaRail === 'bank'
                || (is_array($methodTypes) && in_array('us_bank_account', $methodTypes, true));

            if ($paymentIntentId && $purchase->status === 'pending') {
                Bus::dispatchAfterResponse(
                    new RetryBelievePointPurchaseSettlementJob($purchase->id, $paymentIntentId)
                );

                return redirect()->route('believe-points.index')
                    ->with('info', $isBankCheckout
                        ? 'Your bank payment is processing. Believe Points go into Processing BP first and become available after ACH settlement. BRP credits when Stripe confirms payment—often 1–3 business days for ACH.'
                        : 'Your payment is still confirming. Believe Points will be credited automatically in a moment.');
            }

            if (! $paymentIntentId) {
                $purchase->update(['status' => 'failed', 'failure_message' => 'Missing payment intent from checkout session.']);

                return redirect()->route('believe-points.index')
                    ->with('error', 'Payment was not completed. Please try again.');
            }

            $purchase->update(['status' => 'failed']);

            return redirect()->route('believe-points.index')
                ->with('error', 'Payment was not completed. Please try again.');
        } catch (\Exception $e) {
            Log::error('Believe Points success handler error: '.$e->getMessage());

            return redirect()->route('believe-points.index')
                ->with('error', 'An error occurred while processing your purchase. Please contact support.');
        }
    }

    /**
     * Prefer DB link by stripe_session_id (reliable); fall back to session metadata.
     */
    private function resolveBelievePointPurchaseFromCheckoutSession(string $sessionIdStr, \Stripe\Checkout\Session $session): ?BelievePointPurchase
    {
        $uid = Auth::id();
        if (! $uid) {
            return null;
        }

        $bySession = BelievePointPurchase::with('user')
            ->where('stripe_session_id', $sessionIdStr)
            ->where('user_id', $uid)
            ->first();

        if ($bySession) {
            return $bySession;
        }

        $purchaseId = (int) ($session->metadata->purchase_id ?? 0);

        return $purchaseId > 0
            ? BelievePointPurchase::with('user')->find($purchaseId)
            : null;
    }

    private function checkoutSessionPaymentIntentId(\Stripe\Checkout\Session $session): ?string
    {
        $pi = $session->payment_intent ?? null;
        if (is_string($pi) && $pi !== '') {
            return $pi;
        }
        if (is_object($pi) && ! empty($pi->id)) {
            return (string) $pi->id;
        }

        return null;
    }

    private function checkoutSessionPaymentIntentStatus(\Stripe\Checkout\Session $session): ?string
    {
        $pi = $session->payment_intent ?? null;
        if (is_object($pi) && isset($pi->status)) {
            return (string) $pi->status;
        }

        return null;
    }

    /**
     * Handle cancelled payment.
     */
    public function cancel(Request $request)
    {
        $sessionId = $request->get('session_id');

        if ($sessionId) {
            try {
                $session = Cashier::stripe()->checkout->sessions->retrieve((string) $sessionId, [
                    'expand' => ['payment_intent'],
                ]);
                $purchaseId = (int) ($session->metadata->purchase_id ?? 0);
                $purchase = $purchaseId ? BelievePointPurchase::find($purchaseId) : null;

                if ($purchase && (int) $purchase->user_id === (int) Auth::id()) {
                    $paymentIntentId = $this->checkoutSessionPaymentIntentId($session);
                    $piStatus = $this->checkoutSessionPaymentIntentStatus($session);
                    if ($paymentIntentId && ($piStatus === null || $piStatus === '')) {
                        try {
                            $pi = Cashier::stripe()->paymentIntents->retrieve($paymentIntentId);
                            $piStatus = (string) ($pi->status ?? '');
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }

                    if ($purchase->status === 'pending') {
                        $purchase->update(['status' => 'cancelled']);
                    } elseif ($purchase->status === 'completed'
                        && in_array($piStatus, ['canceled', 'failed'], true)) {
                        BelievePointPurchaseSettlementService::reverseCompletedPurchaseCredits($purchase);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Believe Points cancel handler error: '.$e->getMessage());
            }
        }

        return redirect()->route('believe-points.index')
            ->with('info', 'Purchase was cancelled.');
    }

    /**
     * Display the refund page showing refundable purchases.
     */
    public function refunds(Request $request)
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Get user's current believe points balance
        $currentBalance = $user->currentBelievePoints();

        // Get all completed purchases that can potentially be refunded
        $purchases = BelievePointPurchase::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNull('refunded_at')
            ->where('created_at', '>=', now()->subDays(7))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'amount' => $purchase->amount,
                    'points' => $purchase->points,
                    'created_at' => $purchase->created_at,
                    'can_refund' => $purchase->canBeRefunded() && $purchase->userHasPointsInBalance(),
                    'reason' => ! $purchase->canBeRefunded()
                        ? ($purchase->status !== 'completed' ? 'Purchase not completed' : ($purchase->refunded_at ? 'Already refunded' : 'Outside 7-day refund window'))
                        : (! $purchase->userHasPointsInBalance() ? 'Insufficient points in balance' : null),
                ];
            });

        // Get refund history (all refunded purchases)
        $refundHistory = BelievePointPurchase::where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('refunded_at')
            ->orderBy('refunded_at', 'desc')
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'amount' => $purchase->amount,
                    'points' => $purchase->points,
                    'created_at' => $purchase->created_at,
                    'refunded_at' => $purchase->refunded_at,
                    'refund_status' => $purchase->refund_status,
                    'stripe_refund_id' => $purchase->stripe_refund_id,
                ];
            });

        return Inertia::render('BelievePoints/Refunds', [
            'currentBalance' => $currentBalance,
            'purchases' => $purchases,
            'refundHistory' => $refundHistory,
        ]);
    }

    /**
     * Process a refund for a believe points purchase.
     */
    public function refund(Request $request, $purchaseId)
    {
        Log::info('Believe Points refund: Request received', [
            'purchase_id' => $purchaseId,
            'user_id' => Auth::id(),
            'request_data' => $request->all(),
        ]);

        $user = Auth::user();

        if (! $user) {
            Log::warning('Believe Points refund: Unauthorized', [
                'purchase_id' => $purchaseId,
            ]);

            return redirect()->route('believe-points.refunds')
                ->with('error', 'Unauthorized. Please log in to process refunds.');
        }

        // Find the purchase by ID
        $purchase = BelievePointPurchase::find($purchaseId);

        if (! $purchase) {
            Log::warning('Believe Points refund: Purchase not found', [
                'purchase_id' => $purchaseId,
                'user_id' => $user->id,
            ]);

            return redirect()->route('believe-points.refunds')
                ->with('error', 'Purchase not found.');
        }

        Log::info('Believe Points refund: Purchase found', [
            'purchase_id' => $purchase->id,
            'user_id' => $user->id,
            'purchase_user_id' => $purchase->user_id,
            'status' => $purchase->status,
            'refunded_at' => $purchase->refunded_at,
            'stripe_payment_intent_id' => $purchase->stripe_payment_intent_id,
        ]);

        // Verify ownership
        if ($purchase->user_id !== $user->id) {
            return redirect()->route('believe-points.refunds')
                ->with('error', 'You are not authorized to refund this purchase.');
        }

        // Check if purchase can be refunded
        if (! $purchase->canBeRefunded()) {
            $reason = 'This purchase cannot be refunded.';
            if ($purchase->status !== 'completed') {
                $reason = 'This purchase was not completed.';
            } elseif ($purchase->refunded_at !== null) {
                $reason = 'This purchase has already been refunded.';
            } elseif ($purchase->created_at->lt(now()->subDays(7))) {
                $reason = 'This purchase is outside the 7-day refund window.';
            } elseif (! $purchase->stripe_payment_intent_id) {
                $reason = 'No payment information found for this purchase.';
            }

            return redirect()->route('believe-points.refunds')
                ->with('error', $reason);
        }

        // Check if user still has the points in balance
        if (! $purchase->userHasPointsInBalance()) {
            return redirect()->route('believe-points.refunds')
                ->with('error', 'Refund not possible. You no longer have these points in your balance.');
        }

        try {
            DB::beginTransaction();

            // Process Stripe refund
            if (! $purchase->stripe_payment_intent_id) {
                DB::rollBack();
                Log::error('Believe Points refund: No payment intent', [
                    'purchase_id' => $purchase->id,
                    'user_id' => $user->id,
                ]);

                return redirect()->route('believe-points.refunds')
                    ->with('error', 'No payment information found for this purchase. Cannot process refund.');
            }

            Log::info('Believe Points refund: Processing Stripe refund', [
                'purchase_id' => $purchase->id,
                'user_id' => $user->id,
                'payment_intent' => $purchase->stripe_payment_intent_id,
                'amount' => $purchase->amount,
            ]);

            // Use Cashier's Stripe client which is already configured
            $stripe = Cashier::stripe();
            $refund = $stripe->refunds->create([
                'payment_intent' => $purchase->stripe_payment_intent_id,
                'reason' => 'requested_by_customer',
            ]);

            Log::info('Believe Points refund: Stripe refund created', [
                'purchase_id' => $purchase->id,
                'refund_id' => $refund->id,
                'refund_status' => $refund->status,
            ]);

            // Check refund status
            if ($refund->status === 'succeeded' || $refund->status === 'pending') {
                // Deduct points from user's balance (available first, then processing)
                $user->refresh();
                $pointsToDeduct = (float) $purchase->points;
                $deducted = false;
                if ($purchase->points_released) {
                    $deducted = $user->deductBelievePoints($pointsToDeduct);
                } elseif ($user->deductProcessingBelievePoints($pointsToDeduct)) {
                    $deducted = true;
                } else {
                    $fromProcessing = round((float) ($user->processing_believe_points ?? 0), 2);
                    $fromAvailable = round($pointsToDeduct - $fromProcessing, 2);
                    if ($fromProcessing > 0 && $user->deductProcessingBelievePoints($fromProcessing)) {
                        $deducted = $fromAvailable <= 0 || $user->deductBelievePoints($fromAvailable);
                    }
                }

                if (! $deducted) {
                    DB::rollBack();
                    Log::error('Believe Points refund: Failed to deduct points', [
                        'purchase_id' => $purchase->id,
                        'user_id' => $user->id,
                        'points_to_deduct' => $purchase->points,
                        'current_balance' => $user->believe_points,
                    ]);

                    return redirect()->route('believe-points.refunds')
                        ->with('error', 'Failed to deduct points from balance. Please contact support.');
                }

                $rewardClaw = round((float) ($purchase->reward_points_awarded ?? 0), 2);
                if ($rewardClaw > 0) {
                    if (! $user->deductRewardPoints(
                        $rewardClaw,
                        'believe_points_purchase_refund',
                        $purchase->id,
                        'Reward points reversed for refunded Believe Points bank purchase',
                        ['purchase_id' => $purchase->id]
                    )) {
                        Log::warning('Believe Points refund: Could not deduct reward points', [
                            'purchase_id' => $purchase->id,
                            'user_id' => $user->id,
                            'reward_points' => $rewardClaw,
                        ]);
                    }
                }

                // Update purchase record
                $purchase->update([
                    'stripe_refund_id' => $refund->id,
                    'refunded_at' => now(),
                    'refund_status' => $refund->status,
                ]);

                BelievePointsWalletLedgerService::recordPurchaseRefund($purchase->fresh(), (float) $purchase->points);

                $refundedUsd = $purchase->checkout_total !== null
                    ? (float) $purchase->checkout_total
                    : (float) $purchase->amount;

                if (! Transaction::query()
                    ->where('related_type', BelievePointPurchase::class)
                    ->where('related_id', $purchase->id)
                    ->where('type', 'refund')
                    ->exists()) {
                    Transaction::create([
                        'user_id' => $user->id,
                        'related_id' => $purchase->id,
                        'related_type' => BelievePointPurchase::class,
                        'type' => 'refund',
                        'status' => Transaction::STATUS_COMPLETED,
                        'amount' => round(max(0, $refundedUsd), 2),
                        'fee' => 0,
                        'currency' => 'USD',
                        'payment_method' => 'stripe',
                        'transaction_id' => $refund->id,
                        'processed_at' => now(),
                        'meta' => [
                            'source' => 'believe_points_purchase_refund',
                            'believe_point_purchase_id' => $purchase->id,
                            'stripe_refund_id' => $refund->id,
                            'stripe_payment_intent_id' => $purchase->stripe_payment_intent_id,
                            'points_deducted' => (float) $purchase->points,
                            'description' => 'Believe Points purchase refund (Stripe)',
                        ],
                    ]);
                }

                DB::commit();

                $user->refresh(); // Refresh to get latest balance

                Log::info('Believe Points refund processed successfully', [
                    'purchase_id' => $purchase->id,
                    'user_id' => $user->id,
                    'points' => $purchase->points,
                    'refund_id' => $refund->id,
                    'refund_status' => $refund->status,
                    'new_balance' => $user->believe_points,
                    'refund_amount_usd' => $refundedUsd,
                ]);

                return redirect()->route('believe-points.refunds')
                    ->with('success', 'Refund processed successfully! '.number_format((float) $purchase->points, 2).' Believe Points have been deducted from your balance. The full Stripe charge of $'.number_format($refundedUsd, 2).' will be returned to your original payment method within 5-10 business days.');
            } else {
                DB::rollBack();
                Log::error('Believe Points refund: Invalid refund status', [
                    'purchase_id' => $purchase->id,
                    'refund_status' => $refund->status,
                ]);

                return redirect()->route('believe-points.refunds')
                    ->with('error', 'Refund failed with status: '.$refund->status.'. Please contact support.');
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            DB::rollBack();
            Log::error('Believe Points refund: Stripe API error', [
                'purchase_id' => $purchase->id ?? null,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'stripe_code' => $e->getStripeCode(),
            ]);

            return redirect()->route('believe-points.refunds')
                ->with('error', 'Failed to process refund: '.$e->getMessage().'. Please contact support if this issue persists.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Believe Points refund: General error', [
                'purchase_id' => $purchase->id ?? null,
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('believe-points.refunds')
                ->with('error', 'Failed to process refund: '.$e->getMessage().'. Please contact support if this issue persists.');
        }
    }

    /**
     * Save auto top-up preferences (threshold, amount, on/off).
     */
    public function updateAutoReplenishSettings(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $isEnabled = (bool) AdminSetting::get('believe_points_enabled', true);
        if (! $isEnabled) {
            return redirect()->route('believe-points.index')
                ->with('error', 'Believe Points purchases are currently disabled.');
        }

        $minPurchaseAmount = (float) AdminSetting::get('believe_points_min_purchase', 10.00);
        $maxPurchaseAmount = (float) AdminSetting::get('believe_points_max_purchase', 10000.00);

        $enabled = $request->boolean('enabled');

        if ($enabled) {
            $validated = $request->validate([
                'enabled' => ['required', 'boolean'],
                'threshold' => ['required', 'numeric', 'min:0', 'max:100000'],
                'amount' => ['required', 'numeric', 'min:'.$minPurchaseAmount, 'max:'.$maxPurchaseAmount],
                'auto_replenish_policy_accepted' => ['accepted'],
                'saved_payment_method_id' => ['nullable', 'string', 'max:255'],
            ]);

            $cardId = UserStripePaymentMethodService::resolveSavedCardId(
                $user,
                $validated['saved_payment_method_id'] ?? $user->believe_points_auto_replenish_pm_id,
            );

            if (! $cardId) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'Select a saved card for auto top-up, or add one in Payment Methods.');
            }

            try {
                UserStripePaymentMethodService::syncAutoReplenishCard($user, $cardId);
            } catch (\Throwable $e) {
                Log::error('Believe Points auto-replenish card sync error', [
                    'user_id' => $user->id,
                    'payment_method' => $cardId,
                    'message' => $e->getMessage(),
                ]);

                return redirect()->route('believe-points.index')
                    ->with('error', 'Could not use the selected card for auto top-up. Pick another saved card.');
            }

            $threshold = (float) $validated['threshold'];
            $amount = (float) $validated['amount'];
            $user->update([
                'believe_points_auto_replenish_enabled' => true,
                'believe_points_auto_replenish_threshold' => $threshold,
                'believe_points_auto_replenish_amount' => $amount,
                'believe_points_auto_replenish_agreed_at' => now(),
            ]);

            $user->refresh();
            Bus::dispatchSync(new ProcessBelievePointsAutoReplenishJob($user->id));

            return redirect()->route('believe-points.index')
                ->with('success', 'Auto top-up is on. We charge your saved card when your balance is at or below your threshold (including right now if that already applies). At most once per hour.');
        }

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $user->update([
            'believe_points_auto_replenish_enabled' => false,
        ]);

        return redirect()->route('believe-points.index')
            ->with('success', 'Auto top-up is turned off.');
    }

    /**
     * Stripe Checkout (setup mode) to save a card for off-session auto charges.
     */
    public function autoReplenishSetupPayment(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        if (! (bool) AdminSetting::get('believe_points_enabled', true)) {
            return redirect()->route('believe-points.index')
                ->with('error', 'Believe Points purchases are currently disabled.');
        }

        try {
            $user->createOrGetStripeCustomer();
            $user->refresh();

            if (! $user->stripe_id) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'Could not create a Stripe billing profile. Please try again or contact support.');
            }

            $currency = strtolower((string) config('cashier.currency', 'usd'));

            // Cashier pins Stripe API 2023-10-16: Checkout Session rejects setup_intent_data[usage]
            // ("unknown parameter"). Omit setup_intent_data; default SetupIntent behavior still allows saving a card.
            $session = Cashier::stripe()->checkout->sessions->create([
                'customer' => $user->stripe_id,
                'mode' => 'setup',
                'currency' => $currency,
                'payment_method_types' => ['card'],
                'success_url' => route('believe-points.auto-replenish.setup-success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('believe-points.index'),
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'believe_points_auto_replenish_setup',
                ],
            ]);

            return Inertia::location($session->url);
        } catch (ApiErrorException $e) {
            Log::error('Believe Points auto-replenish setup Stripe error', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'code' => $e->getStripeCode(),
                'request_id' => $e->getRequestId(),
            ]);

            $hint = config('app.debug')
                ? ' '.$e->getMessage()
                : '';

            return redirect()->route('believe-points.index')
                ->with('error', 'Could not start card setup.'.$hint.' Please try again or contact support.');
        } catch (\Throwable $e) {
            Log::error('Believe Points auto-replenish setup session error', [
                'user_id' => $user->id ?? null,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            $hint = config('app.debug') ? ' '.$e->getMessage() : '';

            return redirect()->route('believe-points.index')
                ->with('error', 'Could not start card setup.'.$hint.' Please try again.');
        }
    }

    public function autoReplenishSetupSuccess(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $sessionId = $request->query('session_id');
        if (! $sessionId) {
            return redirect()->route('believe-points.index')
                ->with('error', 'Invalid setup session.');
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve((string) $sessionId);
            if (($session->metadata->type ?? '') !== 'believe_points_auto_replenish_setup'
                || (int) ($session->metadata->user_id ?? 0) !== (int) $user->id) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'This setup session does not match your account.');
            }

            $setupIntentId = $session->setup_intent;
            if (! $setupIntentId) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'Could not confirm your card. Please try again.');
            }

            $setupIntent = Cashier::stripe()->setupIntents->retrieve(
                is_string($setupIntentId) ? $setupIntentId : $setupIntentId->id
            );
            $pmId = is_string($setupIntent->payment_method)
                ? $setupIntent->payment_method
                : ($setupIntent->payment_method->id ?? null);
            if (! $pmId) {
                return redirect()->route('believe-points.index')
                    ->with('error', 'No payment method was saved.');
            }

            BelievePointsPaymentMethodSyncService::ensurePaymentMethodBelongsToCustomer($user, $pmId);
            $pm = Cashier::stripe()->paymentMethods->retrieve($pmId);
            $card = $pm->card ?? null;

            $user->update([
                'believe_points_auto_replenish_pm_id' => $pmId,
                'believe_points_auto_replenish_card_brand' => $card->brand ?? null,
                'believe_points_auto_replenish_card_last4' => $card->last4 ?? null,
            ]);

            $user->refresh();
            if ($user->believe_points_auto_replenish_enabled) {
                Bus::dispatchSync(new ProcessBelievePointsAutoReplenishJob($user->id));
            }

            return redirect()->route('believe-points.index')
                ->with('success', 'Card saved. Turn on auto top-up and set your threshold and amount.');
        } catch (\Exception $e) {
            Log::error('Believe Points auto-replenish setup success error: '.$e->getMessage());

            return redirect()->route('believe-points.index')
                ->with('error', 'Could not save your card. Please try again.');
        }
    }

    public function autoReplenishRemovePaymentMethod(Request $request): RedirectResponse
    {
        $user = Auth::user();
        if (! $user) {
            return redirect()->route('login');
        }

        $user->update([
            'believe_points_auto_replenish_enabled' => false,
            'believe_points_auto_replenish_pm_id' => null,
            'believe_points_auto_replenish_card_brand' => null,
            'believe_points_auto_replenish_card_last4' => null,
        ]);

        return redirect()->route('believe-points.index')
            ->with('success', 'Auto top-up turned off.');
    }

    /**
     * @return array{
     *     enabled: bool,
     *     threshold: float|null,
     *     amount: float|null,
     *     has_payment_method: bool,
     *     saved_payment_method_id: string|null,
     *     card_brand: string|null,
     *     card_last4: string|null,
     *     last_replenish_at: string|null
     * }
     */
    private function autoReplenishPayloadForUser(User $user): array
    {
        $savedCards = UserStripePaymentMethodService::listCardsForUser($user);
        $selectedCardId = UserStripePaymentMethodService::resolveSavedCardId(
            $user,
            $user->believe_points_auto_replenish_pm_id,
        );

        return [
            'enabled' => (bool) $user->believe_points_auto_replenish_enabled,
            'threshold' => $user->believe_points_auto_replenish_threshold !== null
                ? (float) $user->believe_points_auto_replenish_threshold
                : null,
            'amount' => $user->believe_points_auto_replenish_amount !== null
                ? (float) $user->believe_points_auto_replenish_amount
                : null,
            'has_payment_method' => $selectedCardId !== null || $savedCards !== [],
            'saved_payment_method_id' => $selectedCardId,
            'card_brand' => $user->believe_points_auto_replenish_card_brand,
            'card_last4' => $user->believe_points_auto_replenish_card_last4,
            'last_replenish_at' => $user->believe_points_last_auto_replenish_at?->toIso8601String(),
        ];
    }

    private function ledgerTransactionIdForPurchase(BelievePointPurchase $purchase): ?int
    {
        $id = Transaction::query()
            ->where('related_id', $purchase->id)
            ->where('type', 'purchase')
            ->where(function ($q) {
                $q->where('related_type', BelievePointPurchase::class)
                    ->orWhere('related_type', 'like', '%BelievePointPurchase');
            })
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }
}
