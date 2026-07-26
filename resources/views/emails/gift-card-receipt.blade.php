<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        @if(!empty($readyNotification))
            Your gift card is ready
        @else
            Gift Card Purchase Receipt
        @endif
    </title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background: #f9fafb;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .receipt-box {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .receipt-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .receipt-item:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #6b7280;
        }
        .value {
            color: #111827;
        }
        .card-number {
            font-family: monospace;
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            letter-spacing: 2px;
        }
        .notice {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
            color: #92400e;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>
<body>
    @php
        $delayHours = $delayHours ?? 72;
        $orderNumber = $orderNumber ?? ('GC-'.$giftCard->id);
        $purchaseDate = $giftCard->purchased_at ?? $giftCard->requested_at ?? $giftCard->created_at;
        $faceAmount = (float) $giftCard->amount;
        $platformFee = (float) ($giftCard->meta['platform_fee'] ?? 0);
        $totalCharged = (float) ($giftCard->meta['gift_card_total_charged'] ?? ($faceAmount + $platformFee));
        $amountLabel = number_format($faceAmount, 2).' '.($giftCard->currency ?? 'USD');
        $totalChargedLabel = number_format($totalCharged, 2).' '.($giftCard->currency ?? 'USD');
        $platformFeeLabel = number_format($platformFee, 2).' '.($giftCard->currency ?? 'USD');
    @endphp

    <div class="header">
        @if(!empty($readyNotification))
            <h1>Your gift card is ready</h1>
            <p>You can use it now in your Believe In Unity account.</p>
        @else
            <h1>Gift Card Purchase Receipt</h1>
            <p>Thank you for your purchase!</p>
        @endif
    </div>

    <div class="content">
        <p>Hello{{ $giftCard->user?->name ? ', '.$giftCard->user->name : '' }},</p>

        @if(!empty($readyNotification))
            <p>Great news — your gift card has been processed and is ready to use.</p>

            <div class="receipt-box">
                <h2 style="margin-top: 0;">Gift Card Details</h2>

                <div class="receipt-item">
                    <span class="label">Order Status:</span>
                    <span class="value">Ready</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Gift Card:</span>
                    <span class="value">{{ $giftCard->brand_name }}</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Gift Card Amount:</span>
                    <span class="value"><strong>{{ $amountLabel }}</strong></span>
                </div>

                @if($platformFee > 0)
                <div class="receipt-item">
                    <span class="label">Platform Fee:</span>
                    <span class="value">{{ $platformFeeLabel }}</span>
                </div>
                @endif

                <div class="receipt-item">
                    <span class="label">Total Charged:</span>
                    <span class="value"><strong>{{ $totalChargedLabel }}</strong></span>
                </div>

                <div class="receipt-item">
                    <span class="label">Order Number:</span>
                    <span class="value">{{ $orderNumber }}</span>
                </div>

                @php
                    $displayCredentials = \App\Support\PhazeGiftCardPayload::resolveForDisplay($giftCard);
                @endphp

                @if($displayCredentials['card_number'])
                <div class="receipt-item">
                    <span class="label">Card Number:</span>
                    <span class="value card-number">{{ $displayCredentials['card_number'] }}</span>
                </div>
                @endif

                @if($displayCredentials['pin'])
                <div class="receipt-item">
                    <span class="label">PIN:</span>
                    <span class="value card-number">{{ $displayCredentials['pin'] }}</span>
                </div>
                @endif

                @if($displayCredentials['voucher'])
                <div class="receipt-item">
                    <span class="label">Voucher Code:</span>
                    <span class="value card-number">{{ $displayCredentials['voucher'] }}</span>
                </div>
                @endif

                @if($giftCard->expires_at)
                <div class="receipt-item">
                    <span class="label">Expires:</span>
                    <span class="value">{{ $giftCard->expires_at->format('F j, Y') }}</span>
                </div>
                @endif

                @if($displayCredentials['claim_url'])
                <div class="receipt-item">
                    <span class="label">Redemption Link:</span>
                    <span class="value"><a href="{{ $displayCredentials['claim_url'] }}">{{ $displayCredentials['claim_url'] }}</a></span>
                </div>
                @endif
            </div>

            <p>Open your gift card in the app to view and use your code.</p>
        @elseif(!empty($pendingFulfillment))
            <p>Thank you for your purchase! Your payment has been successfully received.</p>

            <div class="receipt-box">
                <h2 style="margin-top: 0;">Gift Card Purchase Receipt</h2>

                <div class="receipt-item">
                    <span class="label">Order Status:</span>
                    <span class="value">Processing</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Purchase Date:</span>
                    <span class="value">{{ $purchaseDate?->format('F j, Y g:i A') }}</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Order Number:</span>
                    <span class="value">{{ $orderNumber }}</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Gift Card:</span>
                    <span class="value">{{ $giftCard->brand_name }}</span>
                </div>

                <div class="receipt-item">
                    <span class="label">Gift Card Amount:</span>
                    <span class="value"><strong>{{ $amountLabel }}</strong></span>
                </div>

                @if($platformFee > 0)
                <div class="receipt-item">
                    <span class="label">Platform Fee:</span>
                    <span class="value">{{ $platformFeeLabel }}</span>
                </div>
                @endif

                <div class="receipt-item">
                    <span class="label">Total Charged:</span>
                    <span class="value"><strong>{{ $totalChargedLabel }}</strong></span>
                </div>
            </div>

            <div class="notice">
                Your gift card is currently being prepared and will be available within {{ $delayHours }} hours.
                We'll send you another email as soon as your gift card is ready.
            </div>
        @else
            <p>Your gift card purchase has been confirmed. Please find the details below:</p>

            <div class="receipt-box">
                <h2 style="margin-top: 0;">Receipt Details</h2>

                <div class="receipt-item">
                    <span class="label">Gift Card:</span>
                    <span class="value">{{ $giftCard->brand_name }}</span>
                </div>

                @php
                    $instantCredentials = \App\Support\PhazeGiftCardPayload::resolveForDisplay($giftCard);
                @endphp

                @if($instantCredentials['card_number'])
                <div class="receipt-item">
                    <span class="label">Card Number:</span>
                    <span class="value card-number">{{ $instantCredentials['card_number'] }}</span>
                </div>
                @endif

                @if($instantCredentials['pin'])
                <div class="receipt-item">
                    <span class="label">PIN:</span>
                    <span class="value card-number">{{ $instantCredentials['pin'] }}</span>
                </div>
                @endif

                @if($instantCredentials['voucher'])
                <div class="receipt-item">
                    <span class="label">Voucher Code:</span>
                    <span class="value card-number">{{ $instantCredentials['voucher'] }}</span>
                </div>
                @endif

                <div class="receipt-item">
                    <span class="label">Amount:</span>
                    <span class="value"><strong>{{ $amountLabel }}</strong></span>
                </div>

                @if($giftCard->expires_at)
                <div class="receipt-item">
                    <span class="label">Expires:</span>
                    <span class="value">{{ $giftCard->expires_at->format('F j, Y') }}</span>
                </div>
                @endif

                <div class="receipt-item">
                    <span class="label">Purchase Date:</span>
                    <span class="value">{{ $purchaseDate?->format('F j, Y g:i A') }}</span>
                </div>

                @if($giftCard->organization)
                <div class="receipt-item">
                    <span class="label">From Organization:</span>
                    <span class="value">{{ $giftCard->organization->name }}</span>
                </div>
                @endif

                <div class="receipt-item">
                    <span class="label">Receipt Number:</span>
                    <span class="value">GC-{{ $giftCard->id }}-{{ substr($giftCard->card_number ?? uniqid(), 0, 8) }}</span>
                </div>
            </div>

            <p><strong>Important:</strong> Your gift card receipt is attached to this email as a PDF. Please keep it for your records.</p>
        @endif

        <p>Thank you for choosing Believe In Unity.</p>

        <p>Believe In Unity Team</p>
    </div>

    <div class="footer">
        <p>This is an automated email. Please do not reply to this message.</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
    </div>
</body>
</html>
