<?php

namespace App\Services;

use App\Enums\PushNotificationModule;
use App\Jobs\SendWalletTransactionEmail;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ShortChannelNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletTransactionNotifier
{
    public function __construct(
        private readonly FirebaseService $firebaseService,
    ) {}

    public function notify(User $user, Transaction $transaction): void
    {
        if ($transaction->status !== 'completed') {
            return;
        }

        $transactionId = $transaction->id;

        DB::afterCommit(function () use ($user, $transactionId): void {
            $cacheKey = 'wallet_tx_notify:'.$transactionId;
            if (! Cache::add($cacheKey, 1, now()->addDays(30))) {
                return;
            }

            $transaction = Transaction::query()->find($transactionId);
            if ($transaction === null || $transaction->status !== 'completed') {
                Cache::forget($cacheKey);

                return;
            }

            $freshUser = User::query()->find($user->id);
            if ($freshUser === null) {
                Cache::forget($cacheKey);

                return;
            }

            [$title, $body] = $this->buildMessage($transaction);
            $walletUrl = route('wallet.activity');

            $this->sendPush($freshUser, $transaction, $title, $body, $walletUrl);
            $this->sendEmail($freshUser, $transaction, $title, $body, $walletUrl);
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function buildMessage(Transaction $transaction): array
    {
        $amountLabel = '$'.number_format((float) $transaction->amount, 2);
        $meta = is_array($transaction->meta) ? $transaction->meta : [];

        return match ($transaction->type) {
            'deposit' => [
                'Deposit received',
                "Your wallet received {$amountLabel} from a bank deposit.",
            ],
            'transfer_in' => [
                'Money received',
                $this->transferInBody($amountLabel, $meta),
            ],
            'transfer_out' => [
                'Transfer sent',
                $this->transferOutBody($amountLabel, $meta),
            ],
            'withdrawal' => [
                'Withdrawal completed',
                "Your withdrawal of {$amountLabel} to your bank account has completed.",
            ],
            default => [
                'Wallet update',
                "A wallet transaction of {$amountLabel} was completed.",
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transferInBody(string $amountLabel, array $meta): string
    {
        $sender = trim((string) ($meta['sender_name'] ?? $meta['sender_organization_name'] ?? ''));

        if ($sender !== '') {
            return "You received {$amountLabel} from {$sender}.";
        }

        return "You received {$amountLabel} in your wallet.";
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transferOutBody(string $amountLabel, array $meta): string
    {
        $recipient = trim((string) ($meta['recipient_name'] ?? ''));

        if ($recipient !== '') {
            return "You sent {$amountLabel} to {$recipient}.";
        }

        return "You sent {$amountLabel} from your wallet.";
    }

    private function sendPush(User $user, Transaction $transaction, string $title, string $body, string $walletUrl): void
    {
        $pushTitle = ShortChannelNotification::pushTitle(PushNotificationModule::WalletRewards);
        $pushBody = ShortChannelNotification::pushBody(PushNotificationModule::WalletRewards);
        $pushLinks = ShortChannelNotification::pushDeepLinkData();

        $pushData = $this->firebaseService->stringifyFcmData([
            'type' => 'wallet_transaction_'.$transaction->type,
            'title' => $pushTitle,
            'body' => $pushBody,
            'url' => $pushLinks['url'],
            'click_action' => $pushLinks['click_action'],
            'transaction_id' => (string) $transaction->id,
            'transaction_type' => (string) $transaction->type,
            'amount' => (string) $transaction->amount,
            'source_type' => 'transaction',
            'source_id' => (string) $transaction->id,
            'module_name' => PushNotificationModule::WalletRewards->value,
            'module_record_id' => $transaction->id,
            'created_by' => $user->id,
            'deep_link' => $pushLinks['deep_link'],
        ]);

        try {
            $this->firebaseService->sendToUser($user->id, $pushTitle, $pushBody, $pushData);
        } catch (\Throwable $e) {
            Log::warning('Wallet transaction push notification failed', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'type' => $transaction->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendEmail(User $user, Transaction $transaction, string $title, string $body, string $walletUrl): void
    {
        $email = trim((string) $user->email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        try {
            SendWalletTransactionEmail::dispatch($transaction->id, $user->id, $title, $body, $walletUrl);
        } catch (\Throwable $e) {
            Log::warning('Wallet transaction email dispatch failed', [
                'transaction_id' => $transaction->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
