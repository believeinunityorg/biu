<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gift invite pending</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #7c3aed, #2563eb); padding: 28px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #fff; margin: 0; font-size: 22px;">Your gift is holding</h1>
    </div>
    <div style="background-color: #f8f9fa; padding: 28px; border-radius: 0 0 8px 8px;">
        <p style="font-size: 16px;">
            You sent <strong>{{ $amountLabel }} BP</strong> to <strong>{{ $invite->recipient_email }}</strong>.
        </p>
        <p style="font-size: 15px; color: #555;">
            Those points moved to <strong>Holding</strong> for {{ $holdDays }} days (until {{ $expiresAt }}).
            When they register as a supporter with that email, the gift is credited to their Available BP (Gift BP reporting updates).
            If they don’t register in time, the points return to your Available balance.
        </p>
        <p style="font-size: 15px; color: #555;">
            Changed your mind or used the wrong email? On Gift BP you can <strong>Resend</strong>,
            <strong>Change Email</strong>, or <strong>Cancel</strong> the invitation anytime while it is still pending —
            Cancel returns Holding BP to Available immediately.
        </p>
        <p style="text-align: center; margin: 24px 0;">
            <a href="{{ $manageUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;">
                View Gift BP
            </a>
        </p>
    </div>
</body>
</html>
