{{-- Client invoice email. Plain table layout and inline styles for wide email-client support. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Invoice {{ $number }}</title></head>
<body style="margin:0;padding:0;background:#f4f5f7;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f5f7;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;">
                <tr>
                    <td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#1f2328;">
                        <p style="margin:0 0 16px;">Hi {{ $clientName }},</p>
                        @if (! empty($customMessage))
                            <p style="margin:0 0 16px;">{!! nl2br(e($customMessage)) !!}</p>
                        @endif
                        <p style="margin:0 0 20px;">Please find invoice <strong>{{ $number }}</strong> attached.</p>
                        <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 24px;font-size:15px;">
                            <tr><td style="padding:2px 24px 2px 0;color:#6b7280;">Total</td><td style="padding:2px 0;font-weight:bold;">{{ $total }}</td></tr>
                            @if ($dueDate)
                                <tr><td style="padding:2px 24px 2px 0;color:#6b7280;">Due</td><td style="padding:2px 0;">{{ $dueDate }}</td></tr>
                            @endif
                        </table>
                        <p style="margin:0;">Thank you,<br>{{ $senderName }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
