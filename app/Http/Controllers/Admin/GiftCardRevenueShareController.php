<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\GiftCardRevenueShareService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GiftCardRevenueShareController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : null;
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : null;

        $statistics = GiftCardRevenueShareService::aggregateStatistics($from, $to);

        $recentPurchases = GiftCard::query()
            ->with(['organization:id,name', 'user:id,name,email'])
            ->whereNotNull('purchased_at')
            ->where('status', '!=', 'failed')
            ->when($from, fn ($q) => $q->where('purchased_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('purchased_at', '<=', $to))
            ->orderByDesc('purchased_at')
            ->limit(25)
            ->get()
            ->map(fn (GiftCard $card) => [
                'id' => $card->id,
                'purchased_at' => $card->purchased_at?->toIso8601String(),
                'brand_name' => $card->brand_name ?? $card->brand,
                'amount' => (float) $card->amount,
                'platform_fee' => $card->platform_fee !== null ? (float) $card->platform_fee : null,
                'platform_fee_biu_share' => $card->platform_fee_biu_share !== null ? (float) $card->platform_fee_biu_share : null,
                'platform_fee_org_share' => $card->platform_fee_org_share !== null ? (float) $card->platform_fee_org_share : null,
                'provider_commission' => $card->total_commission !== null ? (float) $card->total_commission : null,
                'biu_revenue_share' => $card->platform_commission !== null ? (float) $card->platform_commission : null,
                'organization_revenue' => $card->nonprofit_commission !== null ? (float) $card->nonprofit_commission : null,
                'merchant_revenue' => (float) ($card->merchant_revenue ?? 0),
                'organization_name' => $card->organization?->name,
                'buyer_name' => $card->user?->name,
                'payment_method' => $card->payment_method,
            ]);

        return Inertia::render('admin/gift-card-revenue/Index', [
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'statistics' => $statistics,
            'recentPurchases' => $recentPurchases,
        ]);
    }
}
