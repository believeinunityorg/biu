<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Gift Card Receipt</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #667eea;
        }
        .header h1 {
            color: #667eea;
            font-size: 24px;
            margin-bottom: 10px;
        }
        .receipt-info {
            margin: 20px 0;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #667eea;
            margin: 20px 0 10px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #667eea;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .label {
            font-weight: bold;
            color: #6b7280;
            width: 40%;
        }
        .value {
            color: #111827;
            width: 60%;
            text-align: right;
        }
        .card-number {
            font-family: monospace;
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
            letter-spacing: 3px;
            background: #f3f4f6;
            padding: 8px;
            border-radius: 4px;
            text-align: center;
        }
        .card-details-box {
            background: #f9fafb;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        .card-details-box .info-row {
            border-bottom: 1px solid #d1d5db;
        }
        .amount {
            font-size: 20px;
            font-weight: bold;
            color: #059669;
        }
        .expiry-date {
            font-size: 16px;
            font-weight: bold;
            color: #dc2626;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            font-size: 10px;
            color: #6b7280;
        }
        .note {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            font-size: 11px;
        }
        .status-badge {
            display: inline-block;
            background: #059669;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎁 Gift Card Receipt</h1>
        <p>Purchase Confirmed</p>
    </div>

    <div class="receipt-info">
        <!-- Receipt Information -->
        <div class="section-title">Receipt Information</div>
        <div class="info-row">
            <span class="label">Receipt Number:</span>
            <span class="value">GC-{{ $giftCard->id }}-{{ substr($giftCard->card_number ?? uniqid(), 0, 8) }}</span>
        </div>

        <div class="info-row">
            <span class="label">Purchase Date:</span>
            <span class="value">{{ $giftCard->purchased_at ? $giftCard->purchased_at->format('F j, Y \a\t g:i A') : 'N/A' }}</span>
        </div>

        <div class="info-row">
            <span class="label">Status:</span>
            <span class="value">
                @php
                    $status = $giftCard->status ?? 'pending';
                    $statusColors = [
                        'active' => '#059669',
                        'pending' => '#f59e0b',
                        'failed' => '#dc2626',
                        'used' => '#6b7280',
                        'expired' => '#dc2626',
                        'inactive' => '#6b7280',
                    ];
                    $statusColor = $statusColors[$status] ?? '#6b7280';
                    $statusLabel = ucfirst($status);
                @endphp
                <span class="status-badge" style="background: {{ $statusColor }};">
                    {{ $statusLabel }}
                </span>
            </span>
        </div>

        <!-- Gift Card Details - Prominent Section -->
        <div class="section-title">Gift Card Details</div>
        <div class="card-details-box">
            <div class="info-row">
                <span class="label">Brand:</span>
                <span class="value"><strong>{{ $giftCard->brand_name }}</strong></span>
            </div>

            @php
                $pdfCredentials = \App\Support\PhazeGiftCardPayload::resolveForDisplay($giftCard);
                $faceAmount = (float) ($giftCard->meta['gift_card_face_value'] ?? $giftCard->amount);
                $platformFee = (float) ($giftCard->meta['platform_fee'] ?? $giftCard->meta['biu_fee'] ?? 0);
                $totalCharged = (float) ($giftCard->meta['gift_card_total_charged'] ?? ($faceAmount + $platformFee));
                $currency = $giftCard->currency ?? 'USD';
            @endphp

            @if($pdfCredentials['card_number'])
            <div class="info-row" style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #d1d5db;">
                <span class="label">Card Number:</span>
                <span class="value"></span>
            </div>
            <div style="text-align: center; margin: 10px 0;">
                <span class="card-number">{{ $pdfCredentials['card_number'] }}</span>
            </div>
            @endif

            @if($pdfCredentials['pin'])
            <div class="info-row" style="margin-top: 10px;">
                <span class="label">PIN:</span>
                <span class="value"></span>
            </div>
            <div style="text-align: center; margin: 8px 0;">
                <span class="card-number">{{ $pdfCredentials['pin'] }}</span>
            </div>
            @endif

            @if($pdfCredentials['voucher'])
            <div class="info-row" style="margin-top: 10px;">
                <span class="label">Voucher Code:</span>
                <span class="value"></span>
            </div>
            <div style="text-align: center; margin: 8px 0;">
                <span class="card-number">{{ $pdfCredentials['voucher'] }}</span>
            </div>
            @endif

            @if($pdfCredentials['claim_url'])
            <div class="info-row" style="margin-top: 10px;">
                <span class="label">Redemption Link:</span>
                <span class="value" style="word-break: break-all; text-align: left;">{{ $pdfCredentials['claim_url'] }}</span>
            </div>
            @endif

            <div class="info-row" style="margin-top: 15px;">
                <span class="label">Gift Card Amount:</span>
                <span class="value">{{ number_format($faceAmount, 2) }} {{ $currency }}</span>
            </div>

            @if($platformFee > 0)
            <div class="info-row" style="margin-top: 8px;">
                <span class="label">Platform Fee:</span>
                <span class="value">{{ number_format($platformFee, 2) }} {{ $currency }}</span>
            </div>
            @endif

            <div class="info-row" style="margin-top: 8px;">
                <span class="label">Total Charged:</span>
                <span class="value amount">{{ number_format($totalCharged, 2) }} {{ $currency }}</span>
            </div>

            @if($giftCard->expires_at)
            <div class="info-row" style="margin-top: 15px;">
                <span class="label">Expiry Date:</span>
                <span class="value expiry-date">{{ $giftCard->expires_at->format('F j, Y') }}</span>
            </div>
            @endif
        </div>

        <!-- Customer Information -->
        @if($giftCard->user)
        <div class="section-title">Customer Information</div>
        <div class="info-row">
            <span class="label">Name:</span>
            <span class="value">{{ $giftCard->user->name }}</span>
        </div>

        <div class="info-row">
            <span class="label">Email:</span>
            <span class="value">{{ $giftCard->user->email }}</span>
        </div>
        @endif

        @if($giftCard->organization)
        <div class="info-row">
            <span class="label">Organization:</span>
            <span class="value">{{ $giftCard->organization->name }}</span>
        </div>
        @endif
    </div>

    <div class="note">
        <strong>📌 Important:</strong> Please keep this receipt for your records. Your gift card is ready to use.
        Card number, PIN (when provided), and expiry date are shown above. If you have any questions, please contact our support team.
    </div>

    <div class="footer">
        <p>Generated on: {{ now()->format('F j, Y \a\t g:i A') }}</p>
        <p>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        <p>This is your official receipt. Please keep it for your records.</p>
    </div>
</body>
</html>
