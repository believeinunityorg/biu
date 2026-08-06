<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Family reunion invite</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:28px 28px 20px;background:linear-gradient(90deg,#9333ea,#2563eb);color:#ffffff;">
                            <p style="margin:0 0 6px;font-size:13px;opacity:.9;">{{ $appName }}</p>
                            <h1 style="margin:0;font-size:24px;line-height:1.3;">Family reunion invite</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6;">
                                <strong>{{ $inviterName }}</strong> invited you to join
                                <strong>{{ $organizationName }}</strong> on {{ $appName }}.
                            </p>
                            @if (!empty($relationshipLabel))
                                <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#64748b;">
                                    Suggested relationship: <strong>{{ $relationshipLabel }}</strong>
                                </p>
                            @endif
                            <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#64748b;">
                                Accept the invite, then confirm your branch and how you are related to the family.
                            </p>
                            <a href="{{ $acceptUrl }}"
                               style="display:inline-block;padding:12px 22px;border-radius:10px;background:linear-gradient(90deg,#9333ea,#2563eb);color:#ffffff;text-decoration:none;font-weight:700;font-size:15px;">
                                Accept invite
                            </a>
                            <p style="margin:24px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">
                                If the button doesn’t work, copy and paste this link into your browser:<br>
                                <span style="color:#7c3aed;word-break:break-all;">{{ $acceptUrl }}</span>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
