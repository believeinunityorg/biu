<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Transaction;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;

class CreditPurchaseController extends Controller
{
    /**
     * @return list<array{id: int, created_at: string, amount_usd: float, status: string, payment_method: string|null, wallet: string, quantity: float|int, summary: string, package: string|null}>
     */
    protected function purchaseHistoryForUser(int $userId): array
    {
        return Transaction::query()
            ->where('user_id', $userId)
            ->where('type', 'credit_purchase')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(function (Transaction $t) {
                $meta = is_array($t->meta) ? $t->meta : [];
                $wallet = isset($meta['credit_wallet']) && is_string($meta['credit_wallet'])
                    ? $meta['credit_wallet']
                    : 'credits';
                $isStudio = $wallet === 'ai_media_studio';
                $qty = $isStudio
                    ? (float) ($meta['ai_media_studio_credits_added'] ?? $meta['media_credits_to_add'] ?? 0)
                    : (int) ($meta['credits_added'] ?? $meta['credits_to_add'] ?? 0);

                return [
                    'id' => $t->id,
                    'created_at' => $t->created_at?->toIso8601String() ?? '',
                    'amount_usd' => round((float) $t->amount, 2),
                    'status' => (string) $t->status,
                    'payment_method' => $t->payment_method !== null ? (string) $t->payment_method : null,
                    'wallet' => $isStudio ? 'ai_media_studio' : 'credits',
                    'quantity' => round($qty, 2),
                    'summary' => (string) ($meta['description'] ?? ($isStudio ? 'AI Video Studio credits' : 'Wallet credits')),
                    'package' => isset($meta['package']) && (is_string($meta['package']) || is_numeric($meta['package']))
                        ? (string) $meta['package']
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{amount: float, credits?: int, media_credits?: int, wallet: string}>
     */
    protected function checkoutPackages(): array
    {
        $packages = [
            'legacy_50k' => ['amount' => 1.0, 'credits' => 50000, 'wallet' => 'credits'],
            'addon_10k' => ['amount' => 2.0, 'credits' => 10000, 'wallet' => 'credits'],
            'addon_25k' => ['amount' => 5.0, 'credits' => 25000, 'wallet' => 'credits'],
            'addon_50k' => ['amount' => 10.0, 'credits' => 50000, 'wallet' => 'credits'],
        ];

        $studio = config('services.ai_media_studio.supporter_packs', []);
        if (is_array($studio)) {
            foreach ($studio as $key => $row) {
                if (! is_string($key) || ! is_array($row)) {
                    continue;
                }
                $usd = (float) ($row['usd'] ?? 0);
                $credits = (int) ($row['credits'] ?? 0);
                if ($usd > 0 && $credits > 0) {
                    $packages[$key] = [
                        'amount' => $usd,
                        'media_credits' => $credits,
                        'wallet' => 'ai_media_studio',
                    ];
                }
            }
        }

        return $packages;
    }

    /**
     * Show the credit purchase page
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Credits/Purchase', [
            'aiMediaStudioCredits' => round((float) ($user->ai_media_studio_credits ?? 0), 2),
            'mediaStudioPacks' => config('services.ai_media_studio.supporter_packs', []),
            'activeWallet' => $request->query('wallet', 'credits'),
            'context' => Organization::forAuthUser($user) ? 'organization' : 'supporter',
            'purchaseHistory' => $this->purchaseHistoryForUser((int) $user->id),
        ]);
    }

    /**
     * Create Stripe checkout session for credit purchase
     */
    public function checkout(Request $request)
    {
        $packages = $this->checkoutPackages();

        $returnRoute = $request->input('return_route', 'ai-chat.index');
        if (! is_string($returnRoute) || $returnRoute === '' || ! Route::has($returnRoute)) {
            $returnRoute = 'ai-chat.index';
        }

        $packageKey = $request->input('package');

        if ($packageKey !== null && $packageKey !== '') {
            $request->validate([
                'package' => ['required', 'string', 'in:'.implode(',', array_keys($packages))],
                'return_route' => ['nullable', 'string'],
            ]);
            $amount = $packages[$packageKey]['amount'];
            $wallet = $packages[$packageKey]['wallet'] ?? 'credits';
            $creditsToAdd = (int) ($packages[$packageKey]['credits'] ?? 0);
            $mediaCreditsToAdd = (int) ($packages[$packageKey]['media_credits'] ?? 0);
        } else {
            $request->validate([
                'amount' => ['required', 'numeric', 'min:1'],
                'return_route' => ['nullable', 'string'],
            ]);
            $packageKey = null;
            $userAmount = (float) $request->input('amount');
            $amount = $userAmount;
            // Legacy: charge requested USD amount and grant fixed 50k wallet credits per checkout (existing behaviour).
            $creditsToAdd = 50000;
            $mediaCreditsToAdd = 0;
            $wallet = 'credits';
        }

        try {
            $user = $request->user();

            $amountInCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd((float) $amount, 'card');

            $grantLabel = $wallet === 'ai_media_studio'
                ? "{$mediaCreditsToAdd} AI Video Studio credits (US\$1 = 1 credit)"
                : "{$creditsToAdd} credits";

            // Record pending transaction
            $transaction = $user->recordTransaction([
                'type' => 'credit_purchase',
                'amount' => $amount,
                'payment_method' => 'stripe',
                'status' => 'pending',
                'meta' => [
                    'credits_to_add' => $creditsToAdd,
                    'media_credits_to_add' => $mediaCreditsToAdd,
                    'credit_wallet' => $wallet,
                    'package' => $packageKey ?? 'legacy_amount',
                    'description' => "Purchase {$grantLabel}",
                ],
            ]);

            // Create checkout session
            $checkout = $user->checkoutCharge(
                $amountInCents,
                "Purchase {$grantLabel}",
                1,
                [
                    'success_url' => route('credits.success').'?session_id={CHECKOUT_SESSION_ID}&return_route='.urlencode($returnRoute),
                    'cancel_url' => route('credits.cancel').'?return_route='.urlencode($returnRoute),
                    'metadata' => [
                        'user_id' => $user->id,
                        'transaction_id' => $transaction->id,
                        'type' => 'credit_purchase',
                        'credits_to_add' => (string) $creditsToAdd,
                        'media_credits_to_add' => (string) $mediaCreditsToAdd,
                        'credit_wallet' => $wallet,
                        'amount' => (string) $amount,
                        'return_route' => $returnRoute,
                        'package' => (string) ($packageKey ?? 'legacy_amount'),
                    ],
                    'payment_method_types' => ['card'],
                ]
            );

            // Return Inertia redirect to Stripe checkout
            return Inertia::location($checkout->url);

        } catch (\Exception $e) {
            Log::error('Credit purchase checkout error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return back()->withErrors([
                'message' => 'Failed to create checkout session. Please try again.',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Purchase AI token / wallet credit add-ons using Believe Points (no Stripe redirect).
     */
    public function payWithBelievePoints(Request $request)
    {
        $packages = collect($this->checkoutPackages())
            ->filter(fn ($p) => ($p['wallet'] ?? 'credits') === 'credits')
            ->all();

        $request->validate([
            'package' => ['required', 'string', 'in:'.implode(',', array_keys($packages))],
            'return_route' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $packageKey = $request->input('package');
        $amountUsd = $packages[$packageKey]['amount'];
        $creditsToAdd = (int) ($packages[$packageKey]['credits'] ?? 0);

        $returnRoute = $request->input('return_route', 'ai-chat.index');
        if (! is_string($returnRoute) || $returnRoute === '' || ! Route::has($returnRoute)) {
            $returnRoute = 'ai-chat.index';
        }

        return DB::transaction(function () use ($user, $amountUsd, $creditsToAdd, $packageKey, $returnRoute) {
            $user->refresh();

            $deducted = $user->deductBelievePoints($amountUsd);
            if (! $deducted) {
                throw ValidationException::withMessages([
                    'message' => 'Insufficient purchased Believe Points. Gift BP can only be spent on gift cards.',
                ]);
            }

            $user->increment('credits', $creditsToAdd);

            $aiTokensIncludedAdded = 0;
            $includedBefore = (int) ($user->fresh()->ai_tokens_included ?? 0);
            if ($includedBefore > 0) {
                $user->increment('ai_tokens_included', $creditsToAdd);
                $aiTokensIncludedAdded = $creditsToAdd;
            }

            $user->recordTransaction([
                'type' => 'credit_purchase',
                'amount' => $amountUsd,
                'payment_method' => 'believe_points',
                'status' => 'completed',
                'meta' => [
                    'credits_to_add' => $creditsToAdd,
                    'package' => $packageKey,
                    'description' => "Purchase {$creditsToAdd} credits (Believe Points)",
                    'bip_from_gifted' => 0,
                    'bip_from_purchased' => $amountUsd,
                    'credits_added' => $creditsToAdd,
                    'ai_tokens_included_added' => $aiTokensIncludedAdded,
                ],
            ]);

            Log::info('Credits purchased with Believe Points', [
                'user_id' => $user->id,
                'package' => $packageKey,
                'amount_usd' => $amountUsd,
                'credits_added' => $creditsToAdd,
            ]);

            $successMsg = "Successfully purchased {$creditsToAdd} wallet credits with Believe Points.";
            if ($aiTokensIncludedAdded > 0) {
                $successMsg .= " Your plan AI token allowance increased by {$aiTokensIncludedAdded} (same pool as AI Chat).";
            }

            return redirect()->route($returnRoute)->with('success', $successMsg);
        });
    }

    /**
     * Handle successful payment
     */
    public function success(Request $request)
    {
        try {
            $sessionId = $request->query('session_id');
            $returnRoute = $request->query('return_route', 'ai-chat.index');

            if (! $sessionId) {
                return redirect()->route($returnRoute)->with('error', 'Invalid session ID.');
            }

            if (! is_string($returnRoute) || $returnRoute === '' || ! Route::has($returnRoute)) {
                $returnRoute = 'ai-chat.index';
            }

            $user = $request->user();

            // Retrieve the checkout session from Stripe
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            if ($session->payment_status !== 'paid') {
                return redirect()->route($returnRoute)->with('error', 'Payment was not completed.');
            }

            // Get metadata from session
            $metadata = $session->metadata ?? [];
            $transactionId = $metadata['transaction_id'] ?? null;
            $returnRouteFromMetadata = $metadata['return_route'] ?? $returnRoute;
            if (! is_string($returnRouteFromMetadata) || $returnRouteFromMetadata === '' || ! Route::has($returnRouteFromMetadata)) {
                $returnRouteFromMetadata = 'ai-chat.index';
            }

            $wallet = is_object($metadata) ? ($metadata->credit_wallet ?? 'credits') : ($metadata['credit_wallet'] ?? 'credits');
            $wallet = is_string($wallet) ? $wallet : 'credits';

            if ($wallet === 'ai_media_studio') {
                $mediaCreditsToAdd = (int) (is_object($metadata) ? ($metadata->media_credits_to_add ?? 0) : ($metadata['media_credits_to_add'] ?? 0));
                if ($mediaCreditsToAdd < 1) {
                    return redirect()->route($returnRouteFromMetadata)->with('error', 'Invalid AI Video Studio credit amount.');
                }

                $user->increment('ai_media_studio_credits', $mediaCreditsToAdd);

                if ($transactionId) {
                    $tx = Transaction::find($transactionId);
                    Transaction::where('id', $transactionId)->update([
                        'status' => 'completed',
                        'meta' => array_merge(
                            $tx?->meta ?? [],
                            [
                                'stripe_session_id' => $sessionId,
                                'stripe_payment_intent' => $session->payment_intent,
                                'payment_status' => $session->payment_status,
                                'ai_media_studio_credits_added' => $mediaCreditsToAdd,
                                'credit_wallet' => 'ai_media_studio',
                            ]
                        ),
                    ]);
                }

                Log::info('AI Video Studio credits purchased', [
                    'user_id' => $user->id,
                    'credits_added' => $mediaCreditsToAdd,
                    'session_id' => $sessionId,
                ]);

                return redirect()->route($returnRouteFromMetadata)->with(
                    'success',
                    "Successfully added {$mediaCreditsToAdd} credits to your AI Video Studio balance (US\$1 = 1 credit)."
                );
            }

            $creditsToAdd = (int) (is_object($metadata) ? ($metadata->credits_to_add ?? 50000) : ($metadata['credits_to_add'] ?? 50000));

            // Add wallet credits
            $user->increment('credits', $creditsToAdd);

            // Same top-up extends plan AI token allowance (used vs included), matching user expectation for
            // "Top up" in AI Chat / newsletter. Skip when ai_tokens_included is 0 (treated as unlimited cap).
            $aiTokensIncludedAdded = 0;
            $includedBefore = (int) ($user->fresh()->ai_tokens_included ?? 0);
            if ($includedBefore > 0) {
                $user->increment('ai_tokens_included', $creditsToAdd);
                $aiTokensIncludedAdded = $creditsToAdd;
            }

            // Update transaction status
            if ($transactionId) {
                $tx = Transaction::find($transactionId);
                Transaction::where('id', $transactionId)->update([
                    'status' => 'completed',
                    'meta' => array_merge(
                        $tx?->meta ?? [],
                        [
                            'stripe_session_id' => $sessionId,
                            'stripe_payment_intent' => $session->payment_intent,
                            'payment_status' => $session->payment_status,
                            'credits_added' => $creditsToAdd,
                            'ai_tokens_included_added' => $aiTokensIncludedAdded,
                        ]
                    ),
                ]);
            }

            Log::info('Credits purchased successfully', [
                'user_id' => $user->id,
                'credits_added' => $creditsToAdd,
                'ai_tokens_included_added' => $aiTokensIncludedAdded,
                'session_id' => $sessionId,
                'return_route' => $returnRouteFromMetadata,
            ]);

            $successMsg = "Successfully purchased {$creditsToAdd} wallet credits.";
            if ($aiTokensIncludedAdded > 0) {
                $successMsg .= " Your plan AI token allowance increased by {$aiTokensIncludedAdded} (same pool as AI Chat).";
            }

            return redirect()->route($returnRouteFromMetadata)->with('success', $successMsg);
        } catch (\Exception $e) {
            Log::error('Credit purchase success handler error', [
                'error' => $e->getMessage(),
                'session_id' => $request->query('session_id'),
            ]);

            return redirect()->route('ai-chat.index')->with('error', 'Error processing payment. Please contact support.');
        }
    }

    /**
     * Handle cancelled payment
     */
    public function cancel(Request $request)
    {
        $returnRoute = $request->query('return_route', 'ai-chat.index');
        if (! is_string($returnRoute) || $returnRoute === '' || ! Route::has($returnRoute)) {
            $returnRoute = 'ai-chat.index';
        }

        return redirect()->route($returnRoute)->with('info', 'Payment was cancelled.');
    }

    /**
     * Handle Stripe webhook for payment confirmation
     * Note: This is handled by Laravel Cashier's webhook system
     * But we can add additional logic here if needed
     * The actual webhook should be configured in Stripe dashboard to point to:
     * /stripe/webhook (Cashier's default webhook endpoint)
     */
    public function webhook(Request $request)
    {
        // Laravel Cashier handles the webhook verification and processing
        // This method can be used for additional credit processing if needed

        return response()->json(['status' => 'success']);
    }
}
