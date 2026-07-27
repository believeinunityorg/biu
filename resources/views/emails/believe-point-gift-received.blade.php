<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You received Believe Points</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #7c3aed, #2563eb); padding: 28px; border-radius: 8px 8px 0 0; text-align: center;">
        <h1 style="color: #fff; margin: 0; font-size: 22px;">You received a gift</h1>
    </div>
    <div style="background-color: #f8f9fa; padding: 28px; border-radius: 0 0 8px 8px;">
        <p style="font-size: 16px;">Hi {{ $recipientName }},</p>
        <p style="font-size: 16px;">
            <strong>{{ $senderName }}</strong> sent you <strong>{{ $amountLabel }} Believe Points</strong>
            @if ($occasion) for <strong>{{ $occasion }}</strong>@endif.
        </p>
        @if ($messageText)
            <div style="background:#fff;border-left:4px solid #7c3aed;padding:16px;margin:20px 0;border-radius:4px;">
                <p style="margin:0;font-size:15px;color:#555;">“{{ $messageText }}”</p>
            </div>
        @endif
        <p style="font-size: 14px; color: #666;">
            These points were added to your Available BP. Gift BP reporting shows how much of your Available BP came from gifts (a subset — not extra BP). Gift BP decreases when you spend Available on closed-loop gift cards.
        </p>
        <p style="text-align: center; margin: 24px 0;">
            <a href="{{ $bpUrl }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;">
                View Believe Points
            </a>
        </p>
    </div>
</body>
</html>
