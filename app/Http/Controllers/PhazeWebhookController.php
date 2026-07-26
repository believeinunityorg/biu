<?php

namespace App\Http\Controllers;

use App\Enums\GiftCardStatus;
use App\Models\GiftCard;
use App\Services\GiftCardRevenueShareService;
use App\Support\PhazeGiftCardPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class PhazeWebhookController extends Controller
{
    /**
     * Handle Phaze webhook for gift card purchase status updates
     * This webhook fires whenever the status of a transaction changes
     *
     * Webhook should be registered with Phaze API using:
     * POST /webhooks
     * {
     *   "url": "https://yourdomain.com/webhooks/phaze",
     *   "apiKey": "your-webhook-secret-key",
     *   "authorizationHeaderName": "authorization"
     * }
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        Log::info('Phaze Webhook Received', [
            'payload' => $payload,
            'ip' => $request->ip(),
        ]);

        try {
            // Verify webhook authorization against database
            $authHeader = $request->header('authorization') ?? $request->header('Authorization');

            if (empty($authHeader)) {
                Log::warning('Phaze webhook missing authorization header');
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Find webhook by API key from database
            $webhook = \App\Models\PhazeWebhook::where('is_active', true)
                ->where('api_key', $authHeader)
                ->first();

            if (!$webhook) {
                Log::warning('Invalid Phaze webhook authorization', [
                    'received_key_preview' => substr($authHeader, 0, 8) . '...',
                    'webhook_count' => \App\Models\PhazeWebhook::where('is_active', true)->count(),
                ]);
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Extract transaction data from payload
            $transactionId = $payload['id'] ?? null;
            $orderId = $payload['orderID'] ?? $payload['orderId'] ?? null;
            $status = PhazeGiftCardPayload::normalizeProviderStatus($payload['status'] ?? null);
            $productId = $payload['productId'] ?? null;
            $externalUserId = $payload['externalUserId'] ?? null;
            $voucher = $payload['voucher'] ?? null;
            $error = $payload['error'] ?? null;

            if (!$transactionId && !$orderId) {
                Log::warning('Phaze webhook missing transaction ID or order ID', [
                    'payload' => $payload,
                ]);
                return response()->json(['error' => 'Missing transaction ID or order ID'], 400);
            }

            // Find gift card by orderId (stored in meta) or external_id
            $giftCard = null;

            if ($orderId) {
                // Search by orderId in meta
                $giftCard = GiftCard::whereJsonContains('meta->orderId', $orderId)->first();
            }

            if (!$giftCard && $transactionId) {
                // Try to find by external_id (Phaze transaction ID)
                $giftCard = GiftCard::where('external_id', $transactionId)->first();
            }

            if (!$giftCard && $externalUserId) {
                // Last resort: find by user_id and productId
                $giftCard = GiftCard::where('user_id', $externalUserId)
                    ->whereJsonContains('meta->productId', $productId)
                    ->whereNotNull('purchased_at')
                    ->orderBy('purchased_at', 'desc')
                    ->first();
            }

            if (!$giftCard) {
                Log::warning('Phaze webhook: Gift card not found', [
                    'transaction_id' => $transactionId,
                    'order_id' => $orderId,
                    'external_user_id' => $externalUserId,
                ]);
                return response()->json(['error' => 'Gift card not found'], 404);
            }

            if (in_array($giftCard->status, [
                GiftCardStatus::PendingFulfillment->value,
                GiftCardStatus::Processing->value,
            ], true)) {
                Log::info('Phaze webhook ignored — gift card awaiting delayed fulfillment pipeline', [
                    'gift_card_id' => $giftCard->id,
                    'status' => $giftCard->status,
                ]);

                return response()->json(['message' => 'Ignored — awaiting internal fulfillment'], 200);
            }

            DB::beginTransaction();

            $resolved = GiftCardRevenueShareService::resolveProviderCommission($payload, (float) $giftCard->amount);
            $providerCommission = $resolved['provider_commission'];
            if ($providerCommission === null && $giftCard->total_commission !== null) {
                $providerCommission = (float) $giftCard->total_commission;
            }

            $commissionPercentage = $resolved['commission_percentage']
                ?? ($giftCard->commission_percentage !== null ? (float) $giftCard->commission_percentage : null);

            $platformCommission = $giftCard->platform_commission;
            $nonprofitCommission = $giftCard->nonprofit_commission;
            $merchantRevenue = $giftCard->merchant_revenue ?? 0;
            $commissionCalculation = $giftCard->meta['commission_calculation'] ?? [];

            if ($providerCommission !== null && $providerCommission > 0 && $platformCommission === null) {
                $split = GiftCardRevenueShareService::splitProviderCommission($providerCommission, $commissionPercentage);
                $commissionPercentage = $split['commission_percentage'];
                $platformCommission = $split['platform_commission'];
                $nonprofitCommission = $split['nonprofit_commission'];
                $merchantRevenue = $split['merchant_revenue'];
                $commissionCalculation = array_merge($commissionCalculation, $split['commission_calculation'], [
                    'updated_via_webhook' => true,
                ]);
            }

            $totalCommission = $providerCommission ?? $giftCard->total_commission;

            $existingMeta = $giftCard->meta ?? [];
            $credentialUpdates = PhazeGiftCardPayload::buildCredentialUpdates(
                $giftCard,
                $payload,
                [
                    'phaze_webhook' => $payload,
                    'phaze_status' => $status,
                    'phaze_updated_at' => now()->toIso8601String(),
                    'orderId' => $orderId ?? $existingMeta['orderId'] ?? null,
                    'phaze_transaction_id' => $transactionId,
                    'commission_calculation' => $commissionCalculation,
                ]
            );

            $updateData = array_merge($credentialUpdates, [
                'external_id' => $transactionId ?? $giftCard->external_id,
                'commission_percentage' => $commissionPercentage ?? $giftCard->commission_percentage,
                'total_commission' => $totalCommission ?? $giftCard->total_commission,
                'platform_commission' => $platformCommission ?? $giftCard->platform_commission,
                'nonprofit_commission' => $nonprofitCommission ?? $giftCard->nonprofit_commission,
                'merchant_revenue' => $merchantRevenue,
            ]);

            // Legacy webhook field still honored if extractor did not find voucher elsewhere
            if ($voucher && empty($updateData['voucher'])) {
                $updateData['voucher'] = $voucher;
            }

            // Update status based on Phaze transaction status ("processed" = success in Phaze admin)
            if (PhazeGiftCardPayload::isProviderFailedStatus($status)) {
                $updateData['status'] = GiftCardStatus::Failed->value;
                Log::warning('Phaze transaction failed', [
                    'gift_card_id' => $giftCard->id,
                    'error' => $error,
                ]);
            } elseif (PhazeGiftCardPayload::isProviderSuccessStatus($status)) {
                $updateData['status'] = GiftCardStatus::Completed->value;
                if (! $giftCard->fulfilled_at) {
                    $updateData['fulfilled_at'] = now();
                }
            }

            $giftCard->update($updateData);

            // Update transaction record if exists
            if ($giftCard->user) {
                $transaction = \App\Models\Transaction::where('related_id', $giftCard->id)
                    ->where('related_type', GiftCard::class)
                    ->first();

                if ($transaction) {
                    $card = $giftCard->fresh();
                    $ledgerSlice = GiftCardRevenueShareService::ledgerMetaSlice(
                        (float) $card->amount,
                        $card->total_commission !== null ? (float) $card->total_commission : null,
                        $card->platform_commission !== null ? (float) $card->platform_commission : null,
                        $card->nonprofit_commission !== null ? (float) $card->nonprofit_commission : null,
                        (float) ($card->merchant_revenue ?? 0)
                    );
                    $transaction->update([
                        'status' => $status === 'failed' ? 'failed' : 'completed',
                        'meta' => array_merge($transaction->meta ?? [], $ledgerSlice, [
                            'phaze_status' => $status,
                            'phaze_transaction_id' => $transactionId,
                            'phaze_error' => $error,
                        ]),
                    ]);
                }
            }

            DB::commit();

            Log::info('Phaze webhook processed successfully', [
                'gift_card_id' => $giftCard->id,
                'status' => $status,
                'transaction_id' => $transactionId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing Phaze webhook: ' . $e->getMessage(), [
                'payload' => $payload,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

