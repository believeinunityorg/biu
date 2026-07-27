<?php

namespace App\Http\Controllers;

use App\Models\AdminSetting;
use App\Models\Raffle;
use App\Models\RaffleTicket;
use App\Models\RaffleWinner;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BiuPlatformFeeService;
use App\Services\StripeProcessingFeeEstimator;
use App\Support\StripeCustomerChargeAmount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Cashier\Cashier;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class RaffleController extends BaseController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): InertiaResponse
    {
        $this->authorizePermission($request, 'raffle.read');

        $query = Raffle::with(['organization', 'tickets', 'winners'])
            ->when($request->search, function ($query, $search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->user()->role === 'organization', function ($query) use ($request) {
                $query->where('organization_id', $request->user()->id);
            });

        $raffles = $query->orderBy('created_at', 'desc')->paginate(12);

        return Inertia::render('raffles/index', [
            'raffles' => $raffles,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request): InertiaResponse
    {
        $this->authorizePermission($request, 'raffle.create');

        return Inertia::render('raffles/create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePermission($request, 'raffle.create');

        foreach (['max_entries_per_person', 'max_free_entries', 'max_donation_entries', 'minimum_age'] as $key) {
            if ($request->input($key) === '') {
                $request->merge([$key => null]);
            }
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'ticket_price' => 'required|numeric|min:0.01',
            'total_tickets' => 'required|integer|min:1|max:10000',
            'draw_date' => 'required|date|after:now',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'prizes' => 'required|array|min:1',
            'prizes.*.name' => 'required|string|max:255',
            'prizes.*.description' => 'nullable|string',
            'winners_count' => 'required|integer|min:1|max:10',
            'sweepstakes_type' => 'nullable|in:free,donation,hybrid',
            'official_rules' => 'nullable|string|max:65000',
            'eligibility_rules' => 'nullable|string|max:65000',
            'max_entries_per_person' => 'nullable|integer|min:1|max:999999',
            'max_free_entries' => 'nullable|integer|min:0|max:999999',
            'max_donation_entries' => 'nullable|integer|min:0|max:999999',
            'minimum_age' => 'nullable|integer|min:13|max:120',
            'country_restrictions_text' => 'nullable|string|max:5000',
            'state_restrictions_text' => 'nullable|string|max:5000',
        ]);

        $countryText = Arr::pull($validated, 'country_restrictions_text');
        $stateText = Arr::pull($validated, 'state_restrictions_text');
        $validated['country_restrictions'] = $this->commaSeparatedToJsonArray($countryText);
        $validated['state_restrictions'] = $this->commaSeparatedToJsonArray($stateText);

        $validated['sweepstakes_type'] = $validated['sweepstakes_type'] ?? 'hybrid';
        $validated['npn_entry_enabled'] = $request->boolean('npn_entry_enabled');
        $validated['entry_free_online_enabled'] = $request->boolean('entry_free_online_enabled');
        $validated['entry_donation_enabled'] = $request->boolean('entry_donation_enabled');
        $validated['entry_mail_in_enabled'] = $request->boolean('entry_mail_in_enabled');
        $validated['entry_social_bonus_enabled'] = $request->boolean('entry_social_bonus_enabled');
        $validated['entry_volunteer_enabled'] = $request->boolean('entry_volunteer_enabled');

        foreach (['max_entries_per_person', 'max_free_entries', 'max_donation_entries', 'minimum_age'] as $key) {
            if (! array_key_exists($key, $validated) || $validated[$key] === '' || $validated[$key] === null) {
                $validated[$key] = null;
            }
        }

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('raffles', 'public');
        }

        $validated['organization_id'] = $request->user()->id;
        $validated['sold_tickets'] = 0;

        $raffle = Raffle::create($validated);

        return redirect()->route('raffles.show', $raffle)
            ->with('success', 'Sweepstakes campaign created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Raffle $raffle): InertiaResponse
    {
        $this->authorizePermission($request, 'raffle.read');

        $raffle->load(['organization.organization', 'tickets.user', 'winners.user', 'winners.ticket']);

        $userTickets = $request->user()
            ? $raffle->tickets()->where('user_id', $request->user()->id)->get()
            : collect();

        return Inertia::render('raffles/show', [
            'raffle' => $raffle,
            'userTickets' => $userTickets,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Raffle $raffle): InertiaResponse
    {
        $this->authorizePermission($request, 'raffle.edit');

        // Only allow editing if raffle is not completed and user owns it
        if ($raffle->status === 'completed' ||
            ($request->user()->role === 'organization' && $raffle->organization_id !== $request->user()->id)) {
            abort(403, 'You cannot edit this sweepstakes campaign.');
        }

        return Inertia::render('raffles/edit', [
            'raffle' => $raffle,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Raffle $raffle): RedirectResponse
    {
        $this->authorizePermission($request, 'raffle.edit');

        // Only allow updating if raffle is not completed and user owns it
        if ($raffle->status === 'completed' ||
            ($request->user()->role === 'organization' && $raffle->organization_id !== $request->user()->id)) {
            abort(403, 'You cannot update this sweepstakes campaign.');
        }

        foreach (['max_entries_per_person', 'max_free_entries', 'max_donation_entries', 'minimum_age'] as $key) {
            if ($request->input($key) === '') {
                $request->merge([$key => null]);
            }
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'ticket_price' => 'required|numeric|min:0.01',
            'total_tickets' => 'required|integer|min:'.$raffle->sold_tickets.'|max:10000',
            'draw_date' => 'required|date|after:now',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'prizes' => 'required|array|min:1',
            'prizes.*.name' => 'required|string|max:255',
            'prizes.*.description' => 'nullable|string',
            'winners_count' => 'required|integer|min:1|max:10',
            'sweepstakes_type' => 'nullable|in:free,donation,hybrid',
            'official_rules' => 'nullable|string|max:65000',
            'eligibility_rules' => 'nullable|string|max:65000',
            'max_entries_per_person' => 'nullable|integer|min:1|max:999999',
            'max_free_entries' => 'nullable|integer|min:0|max:999999',
            'max_donation_entries' => 'nullable|integer|min:0|max:999999',
            'minimum_age' => 'nullable|integer|min:13|max:120',
            'country_restrictions_text' => 'nullable|string|max:5000',
            'state_restrictions_text' => 'nullable|string|max:5000',
        ]);

        $countryText = Arr::pull($validated, 'country_restrictions_text');
        $stateText = Arr::pull($validated, 'state_restrictions_text');
        $validated['country_restrictions'] = $this->commaSeparatedToJsonArray($countryText);
        $validated['state_restrictions'] = $this->commaSeparatedToJsonArray($stateText);

        $validated['sweepstakes_type'] = $validated['sweepstakes_type'] ?? 'hybrid';
        $validated['npn_entry_enabled'] = $request->boolean('npn_entry_enabled');
        $validated['entry_free_online_enabled'] = $request->boolean('entry_free_online_enabled');
        $validated['entry_donation_enabled'] = $request->boolean('entry_donation_enabled');
        $validated['entry_mail_in_enabled'] = $request->boolean('entry_mail_in_enabled');
        $validated['entry_social_bonus_enabled'] = $request->boolean('entry_social_bonus_enabled');
        $validated['entry_volunteer_enabled'] = $request->boolean('entry_volunteer_enabled');

        foreach (['max_entries_per_person', 'max_free_entries', 'max_donation_entries', 'minimum_age'] as $key) {
            if (! array_key_exists($key, $validated) || $validated[$key] === '' || $validated[$key] === null) {
                $validated[$key] = null;
            }
        }

        if ($request->hasFile('image')) {
            if ($raffle->image) {
                Storage::disk('public')->delete($raffle->image);
            }
            $validated['image'] = $request->file('image')->store('raffles', 'public');
        }

        $raffle->update($validated);

        return redirect()->route('raffles.show', $raffle)
            ->with('success', 'Sweepstakes campaign updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Raffle $raffle): RedirectResponse
    {
        $this->authorizePermission($request, 'raffle.delete');

        // Only allow deletion if raffle has no tickets sold
        if ($raffle->sold_tickets > 0) {
            abort(403, 'Cannot delete a sweepstakes campaign that already has entries sold.');
        }

        if ($raffle->image) {
            Storage::disk('public')->delete($raffle->image);
        }

        $raffle->delete();

        return redirect()->route('raffles.index')
            ->with('success', 'Sweepstakes campaign deleted successfully.');
    }

    /**
     * Purchase tickets for a raffle
     */
    public function purchaseTickets(Request $request, Raffle $raffle)
    {
        $this->authorizePermission($request, 'raffle.purchase');

        // Load the organization relationship and its nested organization
        $raffle->load('organization.organization');

        $validated = $request->validate([
            'quantity' => 'required|integer|min:1|max:10',
            'donor_covers_processing_fees' => 'sometimes|boolean',
            'payment_method' => 'nullable|string|in:stripe,believe_points',
        ]);

        $quantity = $validated['quantity'];
        $paymentMethod = $validated['payment_method'] ?? 'stripe';
        /** Ticket line total (what nonprofit keeps toward tickets; fee preview base). */
        $subtotalUsd = round((float) $raffle->ticket_price * $quantity, 2);
        /** Matches /donate: when true, Stripe charge grosses up so estimated card fees are paid by buyer. */
        $donorCovers = $paymentMethod === 'stripe' && $request->boolean('donor_covers_processing_fees');

        // Check if raffle is active and has available tickets
        if (! $raffle->is_active) {
            return back()->withErrors(['error' => 'This raffle is not active.']);
        }

        if ($raffle->available_tickets < $quantity) {
            return back()->withErrors(['error' => 'Not enough tickets available.']);
        }

        $user = $request->user();

        if ($paymentMethod === 'believe_points') {
            if (! (bool) AdminSetting::get('believe_points_enabled', true)) {
                return back()->withErrors(['payment_method' => 'Believe Points purchases are currently disabled.']);
            }

            $user->refresh();
            $purchased = $user->purchasedBelievePointsBalance();
            if ($purchased < $subtotalUsd) {
                return back()->withErrors([
                    'payment_method' => 'Insufficient purchased Believe Points. You need '.number_format($subtotalUsd, 2).' points but only have '.number_format($purchased, 2).' (Gift BP can only be used for gift cards).',
                ]);
            }

            try {
                return DB::transaction(function () use ($user, $raffle, $quantity, $subtotalUsd) {
                    $raffle = Raffle::lockForUpdate()->with('organization.organization')->findOrFail($raffle->id);
                    $raffle->append(['is_active', 'is_completed', 'is_draw_time', 'available_tickets']);

                    if (! $raffle->is_active) {
                        throw new \RuntimeException('This raffle is not active.');
                    }

                    if ($raffle->available_tickets < $quantity) {
                        throw new \RuntimeException('Not enough tickets available.');
                    }

                    $transaction = $user->recordTransaction([
                        'type' => 'purchase',
                        'amount' => $subtotalUsd,
                        'payment_method' => 'believe_points',
                        'status' => 'pending',
                        'processed_at' => null,
                        'meta' => array_merge([
                            'raffle_id' => $raffle->id,
                            'ticket_quantity' => $quantity,
                            'payment_method' => 'believe_points',
                            'believe_points_required' => $subtotalUsd,
                            'description' => 'Purchased '.$quantity.' ticket(s) for raffle: '.$raffle->title,
                        ], BiuPlatformFeeService::ledgerMetaSlice((float) $subtotalUsd)),
                        'related_id' => $raffle->id,
                        'related_type' => 'raffle',
                    ]);

                    $user->refresh();
                    if (! $user->deductBelievePoints($subtotalUsd)) {
                        throw new \RuntimeException('Could not deduct Believe Points. Please try again.');
                    }

                    $transaction->update([
                        'status' => Transaction::STATUS_COMPLETED,
                        'processed_at' => now(),
                        'meta' => array_merge($transaction->meta ?? [], [
                            'believe_points_used' => $subtotalUsd,
                        ], BiuPlatformFeeService::ledgerMetaSlice((float) $subtotalUsd)),
                    ]);

                    $this->fulfillRafflePurchaseLedger(
                        $transaction->fresh(),
                        $raffle->fresh(['organization.organization']),
                        $user->fresh(),
                        $quantity,
                        (float) $subtotalUsd,
                        'believe_points'
                    );

                    return redirect()->route('raffles.success', ['transaction_id' => $transaction->id]);
                });
            } catch (\Throwable $e) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }
        }

        try {
            $checkoutTotalUsd = $subtotalUsd;
            if ($donorCovers) {
                $checkoutTotalUsd = StripeProcessingFeeEstimator::grossUpCardChargeUsdForNetGiftUsd($subtotalUsd);
            }

            // Same cents logic as DonationController Stripe checkout (global pass-through only when org does not cover).
            if (! $donorCovers && StripeProcessingFeeEstimator::customerPaysProcessingFeeEnabled()) {
                $totalInCents = StripeCustomerChargeAmount::chargeCentsFromNetUsd($subtotalUsd, 'card');
            } else {
                $totalInCents = (int) round($checkoutTotalUsd * 100);
            }

            // Record pending transaction
            $transaction = $user->recordTransaction([
                'type' => 'purchase',
                'amount' => $subtotalUsd,
                'payment_method' => 'stripe',
                'status' => 'pending',
                'meta' => array_merge([
                    'raffle_id' => $raffle->id,
                    'ticket_quantity' => $quantity,
                    'donor_covers_processing_fees' => $donorCovers,
                    'checkout_total_usd' => round($checkoutTotalUsd, 2),
                    'description' => 'Purchased '.$quantity.' ticket(s) for raffle: '.$raffle->title,
                ], BiuPlatformFeeService::ledgerMetaSlice((float) $subtotalUsd)),
                'related_id' => $raffle->id,
                'related_type' => 'raffle',
            ]);
            // Create checkout session
            $checkout = $user->checkoutCharge(
                $totalInCents,
                "Raffle tickets for {$raffle->title}",
                1,
                [
                    'success_url' => route('raffles.success').'?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => route('raffles.cancel'),
                    'metadata' => [
                        'raffle_id' => $raffle->id,
                        'quantity' => $quantity,
                        'user_id' => $user->id,
                        'transaction_id' => $transaction->id,
                        'type' => 'raffle_tickets',
                        'donor_covers_processing_fees' => $donorCovers ? '1' : '0',
                    ],
                    'payment_method_types' => ['card', 'afterpay_clearpay', 'affirm'],
                ]
            );

            return Inertia::location($checkout->url);

        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Generate a unique ticket number
     */
    private function generateTicketNumber(): string
    {
        do {
            $ticketNumber = 'T'.str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
        } while (RaffleTicket::where('ticket_number', $ticketNumber)->exists());

        return $ticketNumber;
    }

    /**
     * Create raffle ticket rows, increment sold count, record platform fee, credit organization (after payment is captured).
     *
     * @return array<int, RaffleTicket>
     */
    private function fulfillRafflePurchaseLedger(
        Transaction $transaction,
        Raffle $raffle,
        User $user,
        int $quantity,
        float $totalAmount,
        string $ledgerPaymentMethodLabel = 'stripe'
    ): array {
        $tickets = [];
        for ($i = 0; $i < $quantity; $i++) {
            $ticket = RaffleTicket::create([
                'raffle_id' => $raffle->id,
                'user_id' => $user->id,
                'purchase_transaction_id' => $transaction->id,
                'price' => $raffle->ticket_price,
                'ticket_number' => $this->generateTicketNumber(),
                'purchased_at' => now(),
            ]);
            $ticket->load('raffle.organization.organization');
            $tickets[] = $ticket;
        }

        $raffle->increment('sold_tickets', $quantity);

        $feePct = BiuPlatformFeeService::getSalesPlatformFeePercentage() / 100;
        $administrativeFee = round($totalAmount * $feePct, 2);
        $organizationAmount = round($totalAmount - $administrativeFee, 2);

        $user->recordTransaction([
            'type' => 'administrative_fee',
            'amount' => -$administrativeFee,
            'payment_method' => $ledgerPaymentMethodLabel,
            'status' => 'completed',
            'meta' => [
                'raffle_id' => $raffle->id,
                'ticket_quantity' => $quantity,
                'description' => 'BIU platform fee for raffle ticket purchase: '.$raffle->title,
                'fee_type' => 'administrative',
                'fee_percentage' => BiuPlatformFeeService::getSalesPlatformFeePercentage(),
            ],
            'related_id' => $raffle->id,
            'related_type' => 'raffle',
        ]);

        if ($raffle->organization && $raffle->organization->organization) {
            $raffle->organization->organization->addFund(
                $organizationAmount,
                'raffle_sales',
                [
                    'raffle_id' => $raffle->id,
                    'ticket_quantity' => $quantity,
                    'buyer_id' => $user->id,
                    'total_amount' => $totalAmount,
                    'administrative_fee' => $administrativeFee,
                    'organization_amount' => $organizationAmount,
                    'description' => 'Sale of '.$quantity.' ticket(s) for raffle: '.$raffle->title.' (after BIU platform fee)',
                ]
            );
        }

        return $tickets;
    }

    /**
     * Calculate prize amounts based on position
     */
    private function calculatePrizeAmounts(float $totalPrizePool, int $winnersCount): array
    {
        $amounts = [];

        if ($winnersCount === 1) {
            $amounts[0] = $totalPrizePool;
        } elseif ($winnersCount === 2) {
            $amounts[0] = $totalPrizePool * 0.6; // 60% to 1st place
            $amounts[1] = $totalPrizePool * 0.4; // 40% to 2nd place
        } elseif ($winnersCount === 3) {
            $amounts[0] = $totalPrizePool * 0.5; // 50% to 1st place
            $amounts[1] = $totalPrizePool * 0.3; // 30% to 2nd place
            $amounts[2] = $totalPrizePool * 0.2; // 20% to 3rd place
        } else {
            // For more than 3 winners, distribute evenly
            $amountPerWinner = $totalPrizePool / $winnersCount;
            for ($i = 0; $i < $winnersCount; $i++) {
                $amounts[$i] = $amountPerWinner;
            }
        }

        return $amounts;
    }

    /**
     * Draw winners for a raffle
     */
    public function drawWinners(Request $request, Raffle $raffle): RedirectResponse
    {
        $this->authorizePermission($request, 'raffle.draw');

        // Only allow drawing if it's draw time and raffle is active
        if (! $raffle->is_draw_time) {
            return back()->withErrors(['error' => 'Draw time has not arrived yet.']);
        }

        if ($raffle->status !== 'active') {
            return back()->withErrors(['error' => 'This raffle is not active.']);
        }

        $tickets = $raffle->tickets()->with('user')->where('status', 'active')->get();

        if ($tickets->count() < $raffle->winners_count) {
            return back()->withErrors(['error' => 'Not enough tickets sold for drawing.']);
        }

        DB::transaction(function () use ($raffle, $tickets) {
            // Select random winners
            $winners = $tickets->random($raffle->winners_count);

            // Calculate total prize pool
            // Fee Structure for Prize Distribution:
            // - 8% goes to platform as administrative fee
            // - 92% goes to organization
            // - From the 92%, 80% goes to winners (73.6% of total sales)
            // - From the 92%, 20% stays with organization (18.4% of total sales)
            $totalSales = $raffle->sold_tickets * $raffle->ticket_price;
            $organizationAmount = $totalSales * 0.92; // 92% after 8% admin fee
            $prizePool = $organizationAmount * 0.8; // 80% of organization amount goes to winners
            $prizeAmounts = $this->calculatePrizeAmounts($prizePool, $raffle->winners_count);

            // Create winner records and add prize money to balances
            foreach ($winners as $index => $ticket) {
                $prizeAmount = $prizeAmounts[$index] ?? 0;

                RaffleWinner::create([
                    'raffle_id' => $raffle->id,
                    'raffle_ticket_id' => $ticket->id,
                    'user_id' => $ticket->user_id,
                    'position' => $index + 1,
                    'prize_name' => $raffle->prizes[$index]['name'] ?? 'Prize '.($index + 1),
                    'prize_description' => $raffle->prizes[$index]['description'] ?? null,
                    'prize_amount' => $prizeAmount,
                    'announced_at' => now(),
                ]);

                // Add prize money to winner's balance
                if ($prizeAmount > 0 && $ticket->user) {
                    $ticket->user->addFund(
                        $prizeAmount,
                        'raffle_win',
                        [
                            'raffle_id' => $raffle->id,
                            'position' => $index + 1,
                            'prize_name' => $raffle->prizes[$index]['name'] ?? 'Prize '.($index + 1),
                            'description' => 'Won '.($index + 1).' place in raffle: '.$raffle->title,
                        ]
                    );
                }

                // Mark ticket as winner
                $ticket->update(['status' => 'winner']);
            }

            // Mark raffle as completed
            $raffle->update(['status' => 'completed']);
        });

        return back()->with('success', 'Winners selected successfully.');
    }

    /**
     * Frontend: Display a listing of raffles for users
     */
    public function frontendIndex(Request $request): InertiaResponse
    {
        $query = Raffle::with(['organization', 'tickets', 'winners'])
            ->where('status', 'active') // Only show active raffles to frontend users
            ->when($request->search, function ($query, $search) {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            });

        $raffles = $query->orderBy('created_at', 'desc')->paginate(12);

        // Append computed properties to each raffle
        $raffles->getCollection()->each(function ($raffle) {
            $raffle->append(['is_active', 'is_completed', 'is_draw_time', 'available_tickets']);
        });

        return Inertia::render('frontend/raffles/index', [
            'raffles' => $raffles,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Frontend: Display the specified raffle for users
     */
    public function frontendShow(Request $request, Raffle $raffle): InertiaResponse
    {
        $raffle->load(['organization.organization', 'tickets.user', 'winners.user', 'winners.ticket']);

        // Append computed properties
        $raffle->append(['is_active', 'is_completed', 'is_draw_time', 'available_tickets']);

        $ticketPrice = round((float) $raffle->ticket_price, 2);
        $maxPreviewBase = round($ticketPrice * 10, 2);

        $feePreview = null;
        // Raffle checkout preview is card-only (matches Stripe Checkout payment_method_types on purchase).
        if ($request->filled('fee_preview_amount')) {
            $validator = Validator::make($request->only(['fee_preview_amount', 'fee_preview_donor_covers']), [
                'fee_preview_amount' => 'required|numeric|min:0.01|max:'.$maxPreviewBase,
                'fee_preview_donor_covers' => 'sometimes|boolean',
            ]);
            if (! $validator->fails()) {
                $base = round((float) $validator->validated()['fee_preview_amount'], 2);
                $feePreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($base, $request->boolean('fee_preview_donor_covers'), 'card');
            }
        } else {
            $feePreview = StripeProcessingFeeEstimator::giftFeePreviewPayload($ticketPrice, true, 'card');
        }

        $viewer = $request->user();

        return Inertia::render('frontend/raffles/show', [
            'raffle' => $raffle,
            'feePreview' => $feePreview,
            'believePointsEnabled' => (bool) AdminSetting::get('believe_points_enabled', true),
            'believePointsBalance' => $viewer
                ? round($viewer->purchasedBelievePointsBalance(), 2)
                : 0,
        ]);
    }

    /**
     * Success screen after Believe Points raffle purchase (no Stripe session).
     */
    private function raffleSuccessFromBelievePointsTransaction(Request $request, int $transactionId): InertiaResponse
    {
        $user = $request->user();
        if (! $user) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => null],
            ]);
        }

        $transaction = Transaction::whereKey($transactionId)
            ->where('user_id', $user->id)
            ->first();

        if (! $transaction || $transaction->status !== Transaction::STATUS_COMPLETED) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $user],
            ]);
        }

        $usedBelievePoints = ($transaction->payment_method === 'believe_points')
            || (data_get($transaction->meta, 'payment_method') === 'believe_points');

        if (! $usedBelievePoints) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $user],
            ]);
        }

        $raffleId = (int) data_get($transaction->meta, 'raffle_id', 0);
        if ($raffleId < 1) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $user],
            ]);
        }

        $raffle = Raffle::with('organization.organization')->find($raffleId);
        if (! $raffle) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $user],
            ]);
        }

        $tickets = RaffleTicket::with('raffle.organization.organization')
            ->where('purchase_transaction_id', $transaction->id)
            ->orderBy('id')
            ->get();

        $pointsUsed = round((float) data_get($transaction->meta, 'believe_points_used', $transaction->amount), 2);

        return Inertia::render('raffles/success', [
            'raffle' => $raffle,
            'tickets' => $tickets,
            'paymentMethod' => 'believe_points',
            'believePointsUsed' => $pointsUsed,
            'auth' => [
                'user' => $user,
            ],
        ]);
    }

    /**
     * Handle successful Stripe payment
     *
     * Must be idempotent: Stripe Checkout redirects here with GET; reloading must not mint extra tickets or ledger entries.
     */
    public function success(Request $request): InertiaResponse
    {
        $transactionId = $request->query('transaction_id');
        if ($transactionId && ! $request->query('session_id')) {
            return $this->raffleSuccessFromBelievePointsTransaction($request, (int) $transactionId);
        }

        $sessionId = $request->get('session_id');
        if (! $sessionId) {
            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $request->user()],
            ]);
        }

        try {
            DB::beginTransaction();

            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);

            if (($session->payment_status ?? '') !== 'paid') {
                DB::rollBack();

                return Inertia::render('raffles/cancel', [
                    'auth' => ['user' => $request->user()],
                ]);
            }

            $metadata = $session->metadata;

            $metadataUserId = (int) ($metadata->user_id ?? 0);
            if (! Auth::check() || Auth::id() !== $metadataUserId) {
                DB::rollBack();

                return Inertia::render('raffles/cancel', [
                    'auth' => ['user' => $request->user()],
                ]);
            }

            $raffle = Raffle::with('organization.organization')->findOrFail($metadata->raffle_id);
            $user = User::findOrFail($metadataUserId);
            $quantity = (int) $metadata->quantity;
            $totalAmount = $raffle->ticket_price * $quantity;

            $transaction = Transaction::whereKey($metadata->transaction_id)->lockForUpdate()->firstOrFail();

            if ((int) ($transaction->meta['raffle_id'] ?? 0) !== (int) $raffle->id
                || (int) ($transaction->meta['ticket_quantity'] ?? 0) !== $quantity
                || $transaction->user_id !== $user->id) {
                DB::rollBack();

                return Inertia::render('raffles/cancel', [
                    'auth' => ['user' => $request->user()],
                ]);
            }

            $storedSessionId = data_get($transaction->meta, 'stripe_session_id');

            // Replay / refresh: payment already fulfilled for this Checkout session — do not duplicate tickets or payouts.
            if ($transaction->status === Transaction::STATUS_COMPLETED
                && $storedSessionId === $sessionId) {
                $tickets = RaffleTicket::with('raffle.organization.organization')
                    ->where('purchase_transaction_id', $transaction->id)
                    ->orderBy('id')
                    ->get();

                if ($tickets->isEmpty()) {
                    $qtyMeta = (int) data_get($transaction->meta, 'ticket_quantity', $quantity);
                    $legacy = RaffleTicket::with('raffle.organization.organization')
                        ->where('user_id', $user->id)
                        ->where('raffle_id', $raffle->id)
                        ->whereNull('purchase_transaction_id')
                        ->where('created_at', '>=', $transaction->created_at)
                        ->orderBy('id')
                        ->limit(max(1, $qtyMeta))
                        ->get();
                    if ($legacy->count() === $qtyMeta) {
                        foreach ($legacy as $row) {
                            $row->update(['purchase_transaction_id' => $transaction->id]);
                        }
                        $tickets = $legacy;
                    }
                }

                DB::commit();

                return Inertia::render('raffles/success', [
                    'raffle' => $raffle,
                    'tickets' => $tickets,
                    'paymentMethod' => 'stripe',
                    'auth' => [
                        'user' => $user,
                    ],
                ]);
            }

            if ($transaction->status === Transaction::STATUS_COMPLETED) {
                DB::rollBack();

                return Inertia::render('raffles/cancel', [
                    'auth' => ['user' => $request->user()],
                ]);
            }

            // Update transaction status (first successful fulfillment only)
            $transaction->update([
                'status' => Transaction::STATUS_COMPLETED,
                'processed_at' => now(),
                'meta' => array_merge(
                    $transaction->meta ?? [],
                    [
                        'stripe_session_id' => $sessionId,
                        'stripe_payment_intent' => $session->payment_intent,
                    ],
                    BiuPlatformFeeService::ledgerMetaSlice((float) $totalAmount)
                ),
            ]);

            $tickets = $this->fulfillRafflePurchaseLedger(
                $transaction->fresh(),
                $raffle,
                $user,
                $quantity,
                (float) $totalAmount,
                'stripe'
            );

            DB::commit();

            return Inertia::render('raffles/success', [
                'raffle' => $raffle,
                'tickets' => $tickets,
                'paymentMethod' => 'stripe',
                'auth' => [
                    'user' => $user,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return Inertia::render('raffles/cancel', [
                'auth' => ['user' => $request->user()],
            ]);
        }
    }

    /**
     * Handle cancelled Stripe payment
     */
    public function cancel(Request $request): InertiaResponse
    {
        return Inertia::render('raffles/cancel', [
            'auth' => ['user' => $request->user()],
        ]);
    }

    /**
     * Generate QR code for ticket verification (SVG — no GD/Imagick required; works everywhere).
     */
    public function generateTicketQrCode(RaffleTicket $ticket)
    {
        try {
            $verificationUrl = route('raffles.verify-ticket', $ticket);

            $svg = QrCode::format('svg')
                ->size(280)
                ->margin(2)
                ->errorCorrection('M')
                ->color(0, 0, 0)
                ->backgroundColor(255, 255, 255)
                ->generate($verificationUrl);

            return response($svg, 200, [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Raffle ticket QR SVG failed', [
                'ticket_id' => $ticket->id,
                'message' => $e->getMessage(),
            ]);

            try {
                $fallbackSvg = QrCode::format('svg')
                    ->size(280)
                    ->margin(2)
                    ->generate(route('raffles.verify-ticket', $ticket));

                return response($fallbackSvg, 200, [
                    'Content-Type' => 'image/svg+xml; charset=UTF-8',
                    'Cache-Control' => 'no-cache',
                ]);
            } catch (\Throwable $inner) {
                Log::error('Raffle ticket QR fallback failed', [
                    'ticket_id' => $ticket->id,
                    'message' => $inner->getMessage(),
                ]);

                return response(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect fill="#f3f4f6" width="64" height="64"/><text x="32" y="34" text-anchor="middle" font-size="8" fill="#6b7280">QR</text></svg>',
                    503,
                    ['Content-Type' => 'image/svg+xml; charset=UTF-8'],
                );
            }
        }
    }

    /**
     * Verify ticket using QR code data
     */
    public function verifyTicket(Request $request, RaffleTicket $ticket)
    {
        // Load relationships
        $ticket->load(['raffle.organization', 'user']);

        // Check if ticket is a winner
        $isWinner = $ticket->status === 'winner' || $ticket->is_winner;

        return Inertia::render('raffles/verify-ticket', [
            'ticket' => $ticket,
            'verification_data' => [
                'ticket_number' => $ticket->ticket_number,
                'raffle_title' => $ticket->raffle->title,
                'organization_name' => $ticket->raffle->organization->name,
                'purchased_at' => $ticket->purchased_at,
                'is_winner' => $isWinner,
                'user_name' => $ticket->user->name,
                'user_email' => $ticket->user->email,
            ],
        ]);
    }

    /**
     * @param  string|null  $value  Comma- or newline-separated codes (e.g. US, CA or TX, OK)
     * @return array<int, string>|null
     */
    private function commaSeparatedToJsonArray(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = preg_split('/[,;\n\r]+/', $value);
        $parts = array_values(array_filter(array_map('trim', $parts ?? []), fn ($p) => $p !== ''));

        return $parts === [] ? null : $parts;
    }
}
