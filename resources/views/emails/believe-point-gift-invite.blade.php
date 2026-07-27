<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You've Received a Believe Points Gift!</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #7c3aed, #2563eb); padding: 28px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #fff; margin: 0; font-size: 24px;">🎁 You've Received a Believe Points Gift!</h1>
    </div>
    <div style="background-color: #f8f9fa; padding: 28px; border-radius: 0 0 8px 8px;">
        <p style="font-size: 16px;">Hello,</p>

        <p style="font-size: 16px;">
            Great news! Someone has sent you a gift through Believe In Unity.
        </p>

        <p style="font-size: 16px;">
            You've received <strong>{{ $amountLabel }} Believe Points (BP)</strong>@if ($occasion) for <strong>{{ $occasion }}</strong>@endif.
            @if ($senderName)
                This gift was sent by <strong>{{ $senderName }}</strong>.
            @endif
        </p>

        <p style="font-size: 15px; color: #555;">
            Believe Points are the digital currency of the Believe In Unity platform and can be used to:
        </p>
        <ul style="font-size: 15px; color: #555; padding-left: 20px; margin: 12px 0 20px;">
            <li style="margin-bottom: 8px;">🎁 Purchase gift cards from participating merchants.</li>
            <li style="margin-bottom: 8px;">🛍️ Shop in the Marketplace.</li>
            <li style="margin-bottom: 8px;">🏪 Purchase products and services from organizations and businesses in the Merchant Hub.</li>
            <li style="margin-bottom: 8px;">📚 Enroll in courses offered through the Learning Hub.</li>
        </ul>

        <p style="font-size: 15px; color: #555; margin-bottom: 8px;"><strong>To claim your gift:</strong></p>
        <ol style="font-size: 15px; color: #555; padding-left: 20px; margin: 0 0 20px;">
            <li style="margin-bottom: 8px;">Click the button below.</li>
            <li style="margin-bottom: 8px;">Create your free Believe In Unity account, or sign in if you already have one.</li>
            <li style="margin-bottom: 8px;">Your gift will be added to your Available BP (Gift BP reporting shows it came from a gift).</li>
        </ol>

        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $registerUrl }}" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:bold;font-size:16px;">
                Claim My Gift
            </a>
        </p>

        <p style="font-size: 15px; color: #555;">
            <strong>Important:</strong> Your gift will be held for <strong>{{ $holdDays }} days</strong>
            @if ($expiresAt) (until {{ $expiresAt }})@endif.
            If it is not claimed within that time, the Believe Points will be returned to the sender.
        </p>

        @if ($messageText)
            <div style="background:#fff;border-left:4px solid #7c3aed;padding:16px;margin:20px 0;border-radius:4px;">
                <p style="margin:0 0 6px;font-size:13px;color:#64748b;">Personal message from {{ $senderName }}:</p>
                <p style="margin:0;font-size:15px;color:#555;">“{{ $messageText }}”</p>
            </div>
        @else
            <p style="font-size: 15px; color: #555;">
                If the sender included a personal message, it will be displayed when you claim your gift.
            </p>
        @endif

        <p style="font-size: 15px; color: #555;">
            Thank you for joining the Believe In Unity community. We look forward to welcoming you!
        </p>
        <p style="font-size: 14px; color: #64748b; margin-top: 24px;">
            The Believe In Unity Team
        </p>
        <p style="font-size: 13px; color: #888; margin-top: 16px;">This invitation was sent to {{ $invite->recipient_email }}.</p>
    </div>
</body>
</html>
