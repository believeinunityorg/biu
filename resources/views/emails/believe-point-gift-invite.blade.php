<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $senderName }} sent you a GiftBP gift!</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #7c3aed, #2563eb); padding: 28px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #fff; margin: 0; font-size: 24px;">🎁 Someone was thinking of you!</h1>
    </div>
    <div style="background-color: #f8f9fa; padding: 28px; border-radius: 0 0 8px 8px;">
        <p style="font-size: 18px; font-weight: bold; color: #1e293b; margin-top: 0;">Someone was thinking of you!</p>

        <p style="font-size: 16px;">
            <strong>{{ $senderName }}</strong> has sent you <strong>{{ $amountLabel }} GiftBP</strong>
            @if ($occasion) for <strong>{{ $occasion }}</strong>@endif
            through Believe In Unity.
        </p>

        <p style="font-size: 15px; color: #555;">
            To claim your gift, create your free Believe In Unity account using this email address:
        </p>
        <p style="font-size: 16px; font-weight: bold; color: #1e293b; margin: 8px 0 16px;">{{ $invite->recipient_email }}</p>

        <p style="font-size: 15px; color: #555;">
            <strong>Important:</strong> Please register using this same email address. Your GiftBP is securely waiting for you and will automatically be added to your account after registration.
        </p>

        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $registerUrl }}" style="display:inline-block;background:linear-gradient(135deg,#7c3aed,#2563eb);color:#fff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:bold;font-size:16px;">
                Claim My Gift
            </a>
        </p>

        <div style="background:#fff;border:1px solid #e2e8f0;padding:16px;margin:20px 0;border-radius:8px;">
            <p style="margin:0 0 8px;font-size:14px;color:#64748b;">This gift has been reserved for:</p>
            <p style="margin:0 0 12px;font-size:16px;font-weight:bold;color:#1e293b;">{{ $invite->recipient_email }}</p>
            <p style="margin:0;font-size:14px;color:#555;">
                Please register using this email address so we can automatically deliver your GiftBP to your new account.
            </p>
        </div>

        <p style="font-size: 15px; color: #555;">
            Your gift will be held for <strong>{{ $holdDays }} days</strong>
            @if ($expiresAt) (until {{ $expiresAt }})@endif.
            If it is not claimed within that time, the GiftBP will be returned to the sender.
        </p>

        @if ($messageText)
            <div style="background:#fff;border-left:4px solid #7c3aed;padding:16px;margin:20px 0;border-radius:4px;">
                <p style="margin:0 0 6px;font-size:13px;color:#64748b;">Personal message from {{ $senderName }}:</p>
                <p style="margin:0;font-size:15px;color:#555;">“{{ $messageText }}”</p>
            </div>
        @endif

        <p style="font-size: 14px; color: #64748b; margin-top: 24px;">
            The Believe In Unity Team
        </p>
        <p style="font-size: 13px; color: #888; margin-top: 16px;">This invitation was sent to {{ $invite->recipient_email }}.</p>
    </div>
</body>
</html>
